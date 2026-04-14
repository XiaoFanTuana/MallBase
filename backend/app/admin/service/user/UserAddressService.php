<?php
declare(strict_types=1);

namespace app\admin\service\user;

use app\admin\model\user\User;
use app\admin\model\user\UserAddress;
use app\service\RegionResolverService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * @extends BaseService<UserAddress>
 */
class UserAddressService extends BaseService
{
    protected string $modelClass = UserAddress::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->alias('a')
            ->leftJoin('mb_user u', 'u.id = a.user_id')
            ->field('a.*,u.nickname as user_nickname,u.mobile as user_mobile')
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('a.receiver_name|a.receiver_mobile|a.region_path_text|a.address_detail|u.nickname|u.mobile', '%' . $where['keyword'] . '%');
            })
            ->when(($where['user_id'] ?? null) !== null && $where['user_id'] !== '', function ($q) use ($where) {
                $q->where('a.user_id', $where['user_id']);
            })
            ->when(($where['region_status'] ?? null) !== null && $where['region_status'] !== '', function ($q) use ($where) {
                $q->where('a.region_status', $where['region_status']);
            })
            ->when(($where['is_default'] ?? null) !== null && $where['is_default'] !== '', function ($q) use ($where) {
                $q->where('a.is_default', $where['is_default']);
            })
            ->whereNull('a.delete_time');
    }

    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->buildListQuery($where)->order('a.id', 'desc')->page($page, $limit)->select()->toArray();
        foreach ($list as &$item) {
            $item = $this->refreshRegionState($item);
        }
        $total = $this->buildListQuery($where)->count();
        return compact('total', 'list');
    }

    public function getInfo(int $id): array
    {
        $info = $this->model()->where('id', $id)->whereNull('delete_time')->find();
        if (!$info) {
            throw new BusinessException('地址不存在');
        }

        return $this->refreshRegionState($info->toArray());
    }

    public function create(array $data): int
    {
        $user = $this->model(User::class)->find((int) $data['user_id']);
        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        $regionData = app()->make(RegionResolverService::class)->normalizeAddressRegion($data);
        $payload = array_merge($data, $regionData);

        return $this->transaction(function () use ($payload) {
            if ((int) ($payload['is_default'] ?? 0) === 1) {
                $this->clearDefaultAddress((int) $payload['user_id']);
            }

            $address = $this->model()->create($payload);
            return (int) $address->id;
        });
    }

    public function update(int $id, array $data): bool
    {
        $address = $this->model()->where('id', $id)->whereNull('delete_time')->find();
        if (!$address) {
            throw new BusinessException('地址不存在');
        }

        $userId = (int) ($data['user_id'] ?? $address->user_id);
        $user = $this->model(User::class)->find($userId);
        if (!$user) {
            throw new BusinessException('用户不存在');
        }

        $regionData = app()->make(RegionResolverService::class)->normalizeAddressRegion($data);
        $payload = array_merge($data, $regionData, ['user_id' => $userId]);

        return $this->transaction(function () use ($address, $payload, $userId) {
            if ((int) ($payload['is_default'] ?? 0) === 1) {
                $this->clearDefaultAddress($userId, (int) $address->id);
            }
            $address->save($payload);
            return true;
        });
    }

    public function delete(int $id): bool
    {
        $address = $this->model()->where('id', $id)->whereNull('delete_time')->find();
        if (!$address) {
            throw new BusinessException('地址不存在');
        }

        return (bool) $address->delete();
    }

    public function setDefault(int $id): bool
    {
        $address = $this->model()->where('id', $id)->whereNull('delete_time')->find();
        if (!$address) {
            throw new BusinessException('地址不存在');
        }

        return $this->transaction(function () use ($address) {
            $this->clearDefaultAddress((int) $address->user_id, (int) $address->id);
            $address->save(['is_default' => 1]);
            return true;
        });
    }

    protected function clearDefaultAddress(int $userId, ?int $excludeId = null): void
    {
        $query = $this->model()->where('user_id', $userId)->whereNull('delete_time');
        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }
        $query->update(['is_default' => 0]);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function refreshRegionState(array $item): array
    {
        $valid = app()->make(RegionResolverService::class)->isAddressRegionValid($item);
        $item['region_status'] = $valid ? 1 : 0;
        $item['region_invalid_reason'] = $valid ? null : '关联街道已失效，请重新编辑地址';
        return $item;
    }
}
