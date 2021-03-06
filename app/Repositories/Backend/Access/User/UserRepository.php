<?php

namespace App\Repositories\Backend\Access\User;

use App\Events\Backend\Access\User\UserCreated;
use App\Events\Backend\Access\User\UserDeactivated;
use App\Events\Backend\Access\User\UserDeleted;
use App\Events\Backend\Access\User\UserPasswordChanged;
use App\Events\Backend\Access\User\UserPermanentlyDeleted;
use App\Events\Backend\Access\User\UserReactivated;
use App\Events\Backend\Access\User\UserRestored;
use App\Events\Backend\Access\User\UserUpdated;
use App\Exceptions\GeneralException;
use App\Models\Access\User\User;
use App\Repositories\Backend\Access\Role\RoleRepository;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Class UserRepository.
 */
class UserRepository extends BaseRepository
{
    /**
     * Associated Repository Model.
     */
    const MODEL = User::class;

    /**
     * @var RoleRepository
     */
    protected $role;

    /**
     * @param RoleRepository $role
     */
    public function __construct(RoleRepository $role)
    {
        $this->role = $role;
    }

    /**
     * @param        $permissions
     * @param string $by
     *
     * @return mixed
     */
    public function getByPermission($permissions, $by = 'name')
    {
        if (!is_array($permissions)) {
            $permissions = [$permissions];
        }

        return $this->query()->whereHas('roles.permissions', function ($query) use ($permissions, $by) {
            $query->whereIn('permissions.'.$by, $permissions);
        })->get();
    }

    /**
     * @param        $roles
     * @param string $by
     *
     * @return mixed
     */
    public function getByRole($roles, $by = 'name')
    {
        if (!is_array($roles)) {
            $roles = [$roles];
        }

        return $this->query()->whereHas('roles', function ($query) use ($roles, $by) {
            $query->whereIn('roles.'.$by, $roles);
        })->get();
    }

    /**
     * @param int  $status
     * @param bool $trashed
     *
     * @return mixed
     */
    public function getForDataTable($status = 1, $trashed = false)
    {
        /**
         * Note: You must return deleted_at or the User getActionButtonsAttribute won't
         * be able to differentiate what buttons to show for each row.
         */
        $dataTableQuery = $this->query()
            ->leftJoin('role_user', 'role_user.user_id', '=', 'users.id')
            ->leftJoin('roles', 'role_user.role_id', '=', 'roles.id')
            ->select([
                config('access.users_table').'.id',
                config('access.users_table').'.first_name',
                config('access.users_table').'.last_name',
                config('access.users_table').'.email',
                config('access.users_table').'.status',
                config('access.users_table').'.confirmed',
                config('access.users_table').'.created_at',
                config('access.users_table').'.updated_at',
                config('access.users_table').'.deleted_at',
                DB::raw('GROUP_CONCAT(roles.name) as roles'),
            ])
            ->groupBy('users.id');

        if ($trashed == 'true') {
            return $dataTableQuery->onlyTrashed();
        }

        // active() is a scope on the UserScope trait
        return $dataTableQuery->active($status);
    }

    /**
     * @param Model $input
     */
    public function create($input)
    {
        $data = $input['data'];
        $roles = $input['roles'];

        $permissions = isset($data['permissions']) ? $data['permissions'] : [];
        unset($data['permissions']);

        $user = $this->createUserStub($data);

        DB::transaction(function () use ($user, $data, $roles, $permissions, $input) {
            // Set email type 2
            $email_type = 2;

            if ($user->save()) {

                //User Created, Validate Roles
                if (!count($roles['assignees_roles'])) {
                    throw new GeneralException(trans('exceptions.backend.access.users.role_needed_create'));
                }

                //Attach new roles
                $user->attachRoles($roles['assignees_roles']);

                //Send confirmation email if requested
                if (isset($data['confirmation_email']) && $user->confirmed == 0) {
                    // If user needs confirmation then set email type 1
                    $email_type = 1;
                    $input['data']['confirmation_code'] = $user->confirmation_code;
                }

                event(new UserCreated($user));

                $arrUserPermissions = [];
                if (isset($permissions) && count($permissions) > 0) {
                    foreach ($permissions as $permission) {
                        $arrUserPermissions[] = [
                            'permission_id' => $permission,
                            'user_id'       => $user->id,
                        ];
                    }

                    // Insert multiple rows at once
                    DB::table('permission_user')->insert($arrUserPermissions);
                }

                // Send email to the user
                $options = [
                        'data'                => $input['data'],
                        'email_template_type' => $email_type,
                    ];
                createNotification('', 1, 2, $options);

                return true;
            }

            throw new GeneralException(trans('exceptions.backend.access.users.create_error'));
        });
    }

    /**
     * @param Model $user
     * @param array $input
     *
     * @throws GeneralException
     *
     * @return bool
     */
    public function update(Model $user, array $input)
    {
        $data = $input['data'];
        $roles = $input['roles'];

        $permissions = isset($data['permissions']) ? $data['permissions'] : [];
        unset($data['permissions']);

        $this->checkUserByEmail($data, $user);

        DB::transaction(function () use ($user, $data, $roles, $permissions) {
            if ($user->update($data)) {
                //For whatever reason this just wont work in the above call, so a second is needed for now
                $user->status = isset($data['status']) ? 1 : 0;
                $user->confirmed = isset($data['confirmed']) ? 1 : 0;
                $user->save();

                $this->checkUserRolesCount($roles);
                $this->flushRoles($roles, $user);

                event(new UserUpdated($user));

                $arrUserPermissions = [];
                if (isset($permissions) && count($permissions) > 0) {
                    foreach ($permissions as $permission) {
                        $arrUserPermissions[] = [
                            'permission_id' => $permission,
                            'user_id'       => $user->id,
                        ];
                    }

                    // Insert multiple rows at once
                    DB::table('permission_user')->where('user_id', $user->id)->delete();
                    DB::table('permission_user')->insert($arrUserPermissions);
                }

                return true;
            }

            throw new GeneralException(trans('exceptions.backend.access.users.update_error'));
        });
    }

    /**
     * @param Model $user
     * @param $input
     *
     * @throws GeneralException
     *
     * @return bool
     */
    public function updatePassword(Model $user, $input)
    {
        $user = $this->find(access()->id());
        if (Hash::check($input['old_password'], $user->password)) {
            $user->password = bcrypt($input['password']);
            if ($user->save()) {
                $input['email'] = $user->email;

                // Send email to the user
                $options = [
                            'data'                => $input,
                            'email_template_type' => 4,
                        ];
                createNotification('', $user->id, 2, $options);

                event(new UserPasswordChanged($user));

                return true;
            }

            throw new GeneralException(trans('exceptions.backend.access.users.update_password_error'));
        }

        throw new GeneralException(trans('exceptions.backend.access.users.change_mismatch'));
    }

    /**
     * @param Model $user
     *
     * @throws GeneralException
     *
     * @return bool
     */
    public function delete(Model $user)
    {
        if (access()->id() == $user->id) {
            throw new GeneralException(trans('exceptions.backend.access.users.cant_delete_self'));
        }

        if ($user->delete()) {
            event(new UserDeleted($user));

            return true;
        }

        throw new GeneralException(trans('exceptions.backend.access.users.delete_error'));
    }

    /**
     * @param Model $user
     *
     * @throws GeneralException
     */
    public function forceDelete(Model $user)
    {
        if (is_null($user->deleted_at)) {
            throw new GeneralException(trans('exceptions.backend.access.users.delete_first'));
        }

        DB::transaction(function () use ($user) {
            if ($user->forceDelete()) {
                event(new UserPermanentlyDeleted($user));

                return true;
            }

            throw new GeneralException(trans('exceptions.backend.access.users.delete_error'));
        });
    }

    /**
     * @param Model $user
     *
     * @throws GeneralException
     *
     * @return bool
     */
    public function restore(Model $user)
    {
        if (is_null($user->deleted_at)) {
            throw new GeneralException(trans('exceptions.backend.access.users.cant_restore'));
        }

        if ($user->restore()) {
            event(new UserRestored($user));

            return true;
        }

        throw new GeneralException(trans('exceptions.backend.access.users.restore_error'));
    }

    /**
     * @param Model $user
     * @param $status
     *
     * @throws GeneralException
     *
     * @return bool
     */
    public function mark(Model $user, $status)
    {
        if (access()->id() == $user->id && $status == 0) {
            throw new GeneralException(trans('exceptions.backend.access.users.cant_deactivate_self'));
        }

        $user->status = $status;

        switch ($status) {
            case 0:
                event(new UserDeactivated($user));
            break;

            case 1:
                event(new UserReactivated($user));
            break;
        }

        if ($user->save()) {
            // Send email to the user
            $options = [
                    'data'                => $user,
                    'email_template_type' => 3,
                ];
            createNotification('', $user->id, 2, $options);

            return true;
        }

        throw new GeneralException(trans('exceptions.backend.access.users.mark_error'));
    }

    /**
     * @param  $input
     * @param  $user
     *
     * @throws GeneralException
     */
    protected function checkUserByEmail($input, $user)
    {
        //Figure out if email is not the same
        if ($user->email != $input['email']) {
            //Check to see if email exists
            if ($this->query()->where('email', '=', $input['email'])->first()) {
                throw new GeneralException(trans('exceptions.backend.access.users.email_error'));
            }
        }
    }

    /**
     * @param $roles
     * @param $user
     */
    protected function flushRoles($roles, $user)
    {
        //Flush roles out, then add array of new ones
        $user->detachRoles($user->roles);
        $user->attachRoles($roles['assignees_roles']);
    }

    /**
     * @param  $roles
     *
     * @throws GeneralException
     */
    protected function checkUserRolesCount($roles)
    {
        //User Updated, Update Roles
        //Validate that there's at least one role chosen
        if (count($roles['assignees_roles']) == 0) {
            throw new GeneralException(trans('exceptions.backend.access.users.role_needed'));
        }
    }

    /**
     * @param  $input
     *
     * @return mixed
     */
    protected function createUserStub($input)
    {
        $user = self::MODEL;
        $user = new $user();
        // $user->name = $input['name'];
        $user->first_name = $input['first_name'];
        $user->last_name = $input['last_name'];
        $user->address = $input['address'];
        $user->country_id = 1;
        $user->state_id = $input['state_id'];
        $user->city_id = $input['city_id'];
        $user->zip_code = $input['zip_code'];
        $user->ssn = $input['ssn'];
        $user->email = $input['email'];
        $user->password = bcrypt($input['password']);
        $user->status = isset($input['status']) ? 1 : 0;
        $user->confirmation_code = md5(uniqid(mt_rand(), true));
        $user->confirmed = isset($input['confirmed']) ? 1 : 0;
        $user->created_by = access()->user()->id;

        return $user;
    }
}
