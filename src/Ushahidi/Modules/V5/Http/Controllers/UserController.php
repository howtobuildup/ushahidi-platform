<?php

namespace Ushahidi\Modules\V5\Http\Controllers;

use Ushahidi\Modules\V5\Http\Resources\User\UserCollection;
use Ushahidi\Modules\V5\Http\Resources\User\UserResource;
use Illuminate\Http\Request;
use Ushahidi\Modules\V5\Models\User;
use Ushahidi\Modules\V5\Actions\User\Queries\FetchUserByIdQuery;
use Ushahidi\Modules\V5\Actions\User\Queries\FetchUserQuery;
use Ushahidi\Modules\V5\Actions\User\Commands\CreateUserCommand;
use Ushahidi\Modules\V5\Actions\User\Commands\DeleteUserCommand;
use Ushahidi\Modules\V5\Actions\User\Commands\UpdateUserCommand;
use Illuminate\Support\Facades\Auth;
use Ushahidi\Core\Entity\User as UserEntity;
use Ushahidi\Modules\V5\DTO\UserSearchFields;
use Ushahidi\Modules\V5\Requests\UserRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Ushahidi\Core\Exception\NotFoundException;

class UserController extends V5Controller
{
    public function fieldMonitors()
    {
        $this->authorize('index', new User());

        return response()->json([
            'results' => DB::table('field_monitors')
                ->where('active', true)
                ->orderBy('district')
                ->orderBy('code')
                ->get(['id', 'code', 'district']),
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param integer $id
     * @return mixed
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function show(int $id)
    {
        try {
            $user = $this->queryBus->handle(new FetchUserByIdQuery($id));
        } catch (\Exception $e) {
   // dd(get_class($e));
        }
        $user = $this->queryBus->handle(new FetchUserByIdQuery($id));
        $this->authorize('show', $user);
        return new UserResource($user);
    } //end show()


    /**
     * Display Me.
     *
     * @param integer $id
     * @return mixed
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function showMe()
    {
        $id = AUTH::id();
        if (!$id) {
                $this->authorize('show', null);
        }
        $user = $this->queryBus->handle(new FetchUserByIdQuery($id));
        return new UserResource($user);
    } //end show()

    /**
     * Display the specified resource.
     *
     * @return UserCollection
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(Request $request)
    {
        $this->authorize('index', new User());

        $resourceCollection = new UserCollection(
            $this->queryBus->handle(
                new FetchUserQuery(
                    $request->query('limit', FetchUserQuery::DEFAULT_LIMIT),
                    $request->query('page', 1),
                    $request->query('sortBy', "id"),
                    $request->query('order', FetchUserQuery::DEFAULT_ORDER),
                    new UserSearchFields($request)
                )
            )
        );
        return $resourceCollection;
    } //end index()


    /**
     * Create new User.
     *
     * @param UserRequest $request
     * @return \Illuminate\Http\JsonResponse|UserResource
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function store(UserRequest $request)
    {
        $this->authorize('store', new User());

        $command = new CreateUserCommand(UserEntity::buildEntity($request->input()));
        $this->commandBus->handle($command);
        $this->syncFieldMonitors($command->getId(), $request);
        return new UserResource(
            $this->queryBus->handle(new FetchUserByIdQuery($command->getId()))
        );
    } //end store()


    /**
     * update User.
     *
     * @param UserRequest $request
     * @return \Illuminate\Http\JsonResponse|UserResource
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function update(UserRequest $request, int $id)
    {
        $user = $this->queryBus->handle(new FetchUserByIdQuery($id));
        $userEntity = UserEntity::buildEntity($request->input(), 'update', $user->toArray());
        $this->authorize('update', $user->fill($userEntity->asArray()));

        $this->commandBus->handle(
            new UpdateUserCommand($id, $userEntity)
        );
        $this->syncFieldMonitors($id, $request);
        return new UserResource(
            $this->queryBus->handle(new FetchUserByIdQuery($id))
        );
    } //end update()

    /**
     * update Me.
     *
     * @param UserRequest $request
     * @return \Illuminate\Http\JsonResponse|UserResource
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function updateMe(UserRequest $request)
    {
        $id = AUTH::id();
        if (!$id) {
            $this->authorize('update', null);
        }
        $user = $this->queryBus->handle(new FetchUserByIdQuery($id));
        $this->commandBus->handle(
            new UpdateUserCommand($id, UserEntity::buildEntity($request->input(), 'update', $user->toArray()))
        );
        return $this->showMe();
    } //end update()


    /**
     * delete User.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse|UserResource
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function delete(int $id)
    {
        $user = $this->queryBus->handle(new FetchUserByIdQuery($id));
        $this->authorize('delete', $user);
        $this->commandBus->handle(new DeleteUserCommand($id));
        return $this->deleteResponse($id);
    } //end store()

    private function syncFieldMonitors(int $userId, UserRequest $request): void
    {
        DB::transaction(function () use ($userId, $request) {
            DB::table('partner_field_monitors')->where('partner_user_id', $userId)->delete();

            if ($request->input('role') !== 'saferworld_partner') {
                return;
            }

            $monitorIds = array_values(array_unique(array_map(
                'intval',
                $request->input('field_monitor_ids', [])
            )));
            $now = time();
            $rows = array_map(function ($monitorId) use ($userId, $now) {
                return [
                    'partner_user_id' => $userId,
                    'field_monitor_id' => $monitorId,
                    'created' => $now,
                ];
            }, $monitorIds);

            if (!empty($rows)) {
                DB::table('partner_field_monitors')->insert($rows);
            }
        });
    }
} //end class
