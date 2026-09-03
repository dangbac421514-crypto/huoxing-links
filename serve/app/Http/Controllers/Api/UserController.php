<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\VipPackage;
use App\Services\MembershipService;
use App\Services\UserAccountCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Ugly\Base\Traits\ApiResource;

class UserController extends Controller
{
    use ApiResource;

    public function index(): JsonResponse
    {
        $query = User::search([
            'type' => '=',
            'username' => 'like',
        ], ['vipPackage:id,name', 'parent'])
            ->whereIn('type', [UserType::MEMBER])
            ->orderByDesc('id');

        return $this->paginate($query, UserResource::class);
    }

    // 添加会员
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|regex:/^1[3-9]\d{9}$/|unique:users,username',
            'password' => 'required|min:6',
            'vip_id' => 'nullable|integer|exists:vip_packages,id',
            'reason' => 'required_with:vip_id|string|max:1000',
            'idempotency_key' => 'required_with:vip_id|uuid',
        ]);

        try {
            DB::transaction(function () use ($request): void {
                $user = app(UserAccountCreator::class)->create([
                    'username' => $request->string('username')->toString(),
                    'password' => Hash::make($request->string('password')->toString()),
                    'status' => true,
                    'type' => UserType::MEMBER,
                ]);

                if ($request->filled('vip_id')) {
                    app(MembershipService::class)->open(
                        $user,
                        VipPackage::query()->findOrFail($request->integer('vip_id')),
                        auth('api')->user(),
                        $request->string('reason')->toString(),
                        $request->string('idempotency_key')->toString(),
                    );
                }
            });
        } catch (BusinessRuleException|InvalidArgumentException $exception) {
            return $exception instanceof BusinessRuleException
                ? $this->businessRuleFailure($exception)
                : $this->failed($exception->getMessage(), 422);
        }

        return $this->success();
    }

    // 修改用户、代理
    public function update(Request $request, $id): JsonResponse
    {
        $user = User::query()->whereIn('type', [UserType::MEMBER, UserType::AGENT])->findOrFail($id);

        $request->validate([
            'status' => 'sometimes|boolean',
            'credit' => 'sometimes|numeric',
            'vip_id' => 'nullable|integer|exists:vip_packages,id',
            'action' => 'sometimes|nullable|in:open,renew,upgrade,downgrade,revoke',
            'reason' => 'required_with:action|string|max:1000',
            'idempotency_key' => 'required_with:action|uuid',
            'parent_id' => 'prohibited',
            'referral_code' => 'prohibited',
        ]);

        $action = $request->string('action')->toString();
        if ($request->has('vip_id') && $action === '') {
            return $this->failed('会员变更必须指定 action、reason 和 idempotency_key', 422);
        }
        if ($action !== '' && $action !== 'revoke' && ! $request->filled('vip_id')) {
            return $this->failed('该会员变更必须指定套餐', 422);
        }

        try {
            DB::transaction(function () use ($request, $user, $action): void {
                $params = $request->only(['status', 'credit']);
                if ($params !== []) {
                    User::query()->whereKey($user->id)->update($params);
                }
                if ($action === '') {
                    return;
                }

                $service = app(MembershipService::class);
                $actor = auth('api')->user();
                $reason = $request->string('reason')->toString();
                $key = $request->string('idempotency_key')->toString();
                if ($action === 'revoke') {
                    $service->revoke($user, $actor, $reason, $key);

                    return;
                }

                $package = VipPackage::query()->findOrFail($request->integer('vip_id'));
                match ($action) {
                    'open' => $service->open($user, $package, $actor, $reason, $key),
                    'renew' => $service->renew($user, $package, $actor, $reason, $key),
                    'upgrade' => $service->upgrade($user, $package, $actor, $reason, $key),
                    'downgrade' => $service->scheduleDowngrade($user, $package, $actor, $reason, $key),
                };
            });
        } catch (BusinessRuleException|InvalidArgumentException $exception) {
            return $exception instanceof BusinessRuleException
                ? $this->businessRuleFailure($exception)
                : $this->failed($exception->getMessage(), 422);
        }

        return $this->success();
    }

    private function businessRuleFailure(BusinessRuleException $exception): JsonResponse
    {
        return response()->json([
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
        ], $exception->status);
    }

    public function agent_tree()
    {
        $list = User::query()
            ->select(['id', 'username as label', 'parent_id'])
            ->where('type', UserType::AGENT)
            ->get()
            ->toArray();
        $list = $this->treeLevel($list, 0); // treeLevel

        return $this->success($list);
    }

    private function treeLevel($array, $pid): array
    {
        $tree = [];
        foreach ($array as $key => $value) {
            if ($value['parent_id'] == $pid) {
                $value['children'] = $this->treeLevel($array, $value['id']);
                if (! $value['children']) {
                    unset($value['children']);
                }
                $tree[] = $value;
            }
        }

        return $tree;
    }

    // 代理邀请记录
    public function invite(): JsonResponse
    {
        $query = User::query()
            ->where('parent_id', auth('api')->user()->id)
            ->orderByDesc('id');

        return $this->paginate($query, UserResource::class);
    }
}
