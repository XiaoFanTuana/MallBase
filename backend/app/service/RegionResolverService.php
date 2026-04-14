<?php

declare(strict_types=1);

namespace app\service;

use mall_base\exception\BusinessException;
use think\facade\Db;

class RegionResolverService
{
    /**
     * 获取子级地区
     */
    public function getChildren(int $parentId = 0): array
    {
        return Db::name('region')
            ->where('parent_id', $parentId)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 获取地区路径
     */
    public function getPath(int $id): array
    {
        $region = Db::name('region')->where('id', $id)->find();
        if (!$region) {
            throw new BusinessException('地区不存在');
        }

        $codes = array_filter(explode(',', (string) $region['path_codes']));
        if ($codes === []) {
            return [];
        }

        $list = Db::name('region')
            ->whereIn('code', $codes)
            ->order('level', 'asc')
            ->select()
            ->toArray();

        $map = [];
        foreach ($list as $item) {
            $map[$item['code']] = $item;
        }

        $result = [];
        foreach ($codes as $code) {
            if (isset($map[$code])) {
                $result[] = $map[$code];
            }
        }

        return $result;
    }

    /**
     * 规范化四级地址
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeAddressRegion(array $data): array
    {
        $provinceId = (int) ($data['province_id'] ?? 0);
        $cityId = (int) ($data['city_id'] ?? 0);
        $districtId = (int) ($data['district_id'] ?? 0);
        $streetId = (int) ($data['street_id'] ?? 0);

        if ($provinceId <= 0 || $cityId <= 0 || $districtId <= 0 || $streetId <= 0) {
            throw new BusinessException('请选择完整的省市区街道');
        }

        $regions = Db::name('region')
            ->whereIn('id', [$provinceId, $cityId, $districtId, $streetId])
            ->select()
            ->toArray();

        if (count($regions) !== 4) {
            throw new BusinessException('地区数据不完整或已失效');
        }

        $map = array_column($regions, null, 'id');
        $province = $map[$provinceId] ?? null;
        $city = $map[$cityId] ?? null;
        $district = $map[$districtId] ?? null;
        $street = $map[$streetId] ?? null;

        if (!$province || !$city || !$district || !$street) {
            throw new BusinessException('地区数据不存在');
        }

        if ((int) $province['level'] !== 1 || (int) $city['level'] !== 2 || (int) $district['level'] !== 3 || (int) $street['level'] !== 4) {
            throw new BusinessException('地区层级不正确');
        }

        if ((int) $city['parent_id'] !== $provinceId || (int) $district['parent_id'] !== $cityId || (int) $street['parent_id'] !== $districtId) {
            throw new BusinessException('地区父子关系不匹配');
        }

        foreach ([$province, $city, $district, $street] as $region) {
            if ((int) $region['status'] !== 1) {
                throw new BusinessException('所选地区已停用，请重新选择');
            }
        }

        return [
            'province_id' => $provinceId,
            'province_code' => $province['code'],
            'province_name' => $province['name'],
            'city_id' => $cityId,
            'city_code' => $city['code'],
            'city_name' => $city['name'],
            'district_id' => $districtId,
            'district_code' => $district['code'],
            'district_name' => $district['name'],
            'street_id' => $streetId,
            'street_code' => $street['code'],
            'street_name' => $street['name'],
            'region_path_text' => implode(' / ', [$province['name'], $city['name'], $district['name'], $street['name']]),
            'region_status' => 1,
            'region_invalid_reason' => null,
        ];
    }

    /**
     * 规范化街道规则
     *
     * @param array<int, int|string> $regionIds
     * @return array<string, mixed>
     */
    public function normalizeStreetSelections(array $regionIds): array
    {
        $regionIds = array_values(array_unique(array_map('intval', $regionIds)));
        if ($regionIds === []) {
            throw new BusinessException('请选择街道区域');
        }

        $regions = Db::name('region')
            ->whereIn('id', $regionIds)
            ->select()
            ->toArray();

        if (count($regions) !== count($regionIds)) {
            throw new BusinessException('所选街道存在无效数据');
        }

        $paths = [];
        $codes = [];
        $names = [];
        foreach ($regions as $region) {
            if ((int) $region['level'] !== 4) {
                throw new BusinessException('运费模板必须精确到街道');
            }
            if ((int) $region['status'] !== 1) {
                throw new BusinessException('所选街道已停用，请重新选择');
            }
            $path = $this->getPath((int) $region['id']);
            if (count($path) !== 4) {
                throw new BusinessException('街道路径数据不完整');
            }
            $paths[] = implode(' / ', array_column($path, 'name'));
            $codes[] = $region['code'];
            $names[] = $region['name'];
        }

        return [
            'region_ids' => $regionIds,
            'region_codes' => $codes,
            'region_names' => $names,
            'region_path_texts' => $paths,
            'region_status' => 1,
            'region_invalid_reason' => null,
        ];
    }

    public function isAddressRegionValid(array $address): bool
    {
        $streetId = (int) ($address['street_id'] ?? 0);
        if ($streetId <= 0) {
            return false;
        }

        $street = Db::name('region')->where('id', $streetId)->find();
        if (!$street || (int) $street['status'] !== 1 || (int) $street['level'] !== 4) {
            return false;
        }

        return (int) ($address['district_id'] ?? 0) === (int) $street['parent_id'];
    }
}
