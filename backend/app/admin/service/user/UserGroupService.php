<?php
declare(strict_types=1);

namespace app\admin\service\user;

use app\admin\model\user\UserGroup;
use app\admin\model\user\UserGroupRelation;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use support\Log;

/**
 * 用户分组服务
 */
class UserGroupService extends BaseService
{
    /**
     * 默认 Model 类名
     */
    protected string $modelClass = UserGroup::class;

    /**
     * 获取分组列表
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->model()
            ->withSearch(['name', 'code', 'status'], $where)
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select();

        $total = $this->model()
            ->withSearch(['name', 'code', 'status'], $where)
            ->count();

        return [
            'list' => $list->toArray(),
            'total' => $total,
        ];
    }

    /**
     * 获取分组详情
     */
    public function getInfo(int $id): array
    {
        $info = $this->model()->find($id);

        if (!$info) {
            throw new BusinessException('分组不存在');
        }

        return $info->toArray();
    }

    /**
     * 创建分组
     */
    public function create(array $data): int
    {
        // 检查编码是否重复
        $exists = $this->model()
            ->where('code', $data['code'])
            ->find();

        if ($exists) {
            throw new BusinessException('分组编码已存在');
        }

        $group = $this->model()->save($data);

        Log::info('创建用户分组', [
            'group_id' => $group->id,
            'name' => $data['name'],
            'code' => $data['code'],
        ]);

        return $group->id;
    }

    /**
     * 更新分组
     */
    public function update(int $id, array $data): bool
    {
        $group = $this->model()->find($id);

        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        // 如果修改了编码，检查是否重复
        if (isset($data['code']) && $data['code'] !== $group->code) {
            $exists = $this->model()
                ->where('code', $data['code'])
                ->where('id', '<>', $id)
                ->find();

            if ($exists) {
                throw new BusinessException('分组编码已存在');
            }
        }

        $group->save($data);

        Log::info('更新用户分组', [
            'group_id' => $id,
            'data' => $data,
        ]);

        return true;
    }

    /**
     * 删除分组
     */
    public function delete(int $id): bool
    {
        $group = $this->model()->find($id);

        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        // 检查是否有用户关联
        $userCount = $this->getUserCount($id);

        if ($userCount > 0) {
            throw new BusinessException('该分组下还有用户，无法删除');
        }

        $group->delete();

        Log::info('删除用户分组', [
            'group_id' => $id,
        ]);

        return true;
    }

    /**
     * 获取分组下的用户数
     */
    public function getUserCount(int $groupId): int
    {
        return UserGroupRelation::where('group_id', $groupId)->count();
    }

    /**
     * 批量设置用户分组
     */
    public function batchSetUsers(int $groupId, array $userIds): bool
    {
        $group = $this->model()->find($groupId);

        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        $count = 0;
        foreach ($userIds as $userId) {
            $relation = UserGroupRelation::where('user_id', $userId)
                ->where('group_id', $groupId)
                ->find();

            if (!$relation) {
                UserGroupRelation::create([
                    'user_id' => $userId,
                    'group_id' => $groupId,
                ]);
                $count++;
            }
        }

        Log::info('批量设置用户分组', [
            'group_id' => $groupId,
            'user_count' => $count,
        ]);

        return true;
    }

    /**
     * 移除用户分组
     */
    public function removeUser(int $groupId, int $userId): bool
    {
        $relation = UserGroupRelation::where('user_id', $userId)
            ->where('group_id', $groupId)
            ->find();

        if (!$relation) {
            throw new BusinessException('用户不在该分组中');
        }

        $relation->delete();

        Log::info('移除用户分组', [
            'group_id' => $groupId,
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * 获取用户的所有分组
     */
    public function getUserGroups(int $userId): array
    {
        $relations = UserGroupRelation::where('user_id', $userId)
            ->select();

        $groupIds = array_column($relations->toArray(), 'group_id');

        if (empty($groupIds)) {
            return [];
        }

        $groups = $this->model()
            ->where('status', 1)
            ->whereIn('id', $groupIds)
            ->select();

        return $groups->toArray();
    }

    /**
     * 更新分组状态
     */
    public function updateStatus(int $id, int $status): bool
    {
        $group = $this->model()->find($id);

        if (!$group) {
            throw new BusinessException('分组不存在');
        }

        $group->save(['status' => $status]);

        Log::info('更新分组状态', [
            'group_id' => $id,
            'status' => $status,
        ]);

        return true;
    }
}
