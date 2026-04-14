<?php
declare(strict_types=1);

namespace app\admin\service\setting;

use app\admin\model\setting\FreightTemplate;
use app\admin\model\setting\FreightTemplateRule;
use app\service\RegionResolverService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * @extends BaseService<FreightTemplate>
 */
class FreightTemplateService extends BaseService
{
    protected string $modelClass = FreightTemplate::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(!empty($where['name']), function ($q) use ($where) {
                $q->whereLike('name', '%' . $where['name'] . '%');
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', $where['status']);
            });
    }

    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->buildListQuery($where)->order('id', 'desc')->page($page, $limit)->select()->toArray();
        foreach ($list as &$item) {
            $ruleCount = $this->model(FreightTemplateRule::class)->where('template_id', $item['id'])->count();
            $invalidCount = $this->model(FreightTemplateRule::class)->where('template_id', $item['id'])->where('region_status', 0)->count();
            $item['rule_count'] = $ruleCount;
            $item['invalid_rule_count'] = $invalidCount;
        }
        $total = $this->buildListQuery($where)->count();
        return compact('total', 'list');
    }

    public function getInfo(int $id): array
    {
        $template = $this->model()->find($id);
        if (!$template) {
            throw new BusinessException('运费模板不存在');
        }

        $data = $template->toArray();
        $rules = $this->model(FreightTemplateRule::class)
            ->where('template_id', $id)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($rules as &$rule) {
            $rule = $this->refreshRuleRegionState($rule);
        }

        $data['rules'] = $rules;
        return $data;
    }

    public function create(array $data): int
    {
        return $this->transaction(function () use ($data) {
            $rules = $this->normalizeRules($data['rules'] ?? []);
            $template = $this->model()->create($this->extractTemplateData($data));
            $this->saveRules((int) $template->id, $rules);
            return (int) $template->id;
        });
    }

    public function update(int $id, array $data): bool
    {
        $template = $this->model()->find($id);
        if (!$template) {
            throw new BusinessException('运费模板不存在');
        }

        return $this->transaction(function () use ($template, $data, $id) {
            $rules = $this->normalizeRules($data['rules'] ?? []);
            $template->save($this->extractTemplateData($data));
            $this->model(FreightTemplateRule::class)->where('template_id', $id)->delete();
            $this->saveRules($id, $rules);
            return true;
        });
    }

    public function delete(int $id): bool
    {
        $template = $this->model()->find($id);
        if (!$template) {
            throw new BusinessException('运费模板不存在');
        }

        return $this->transaction(function () use ($template, $id) {
            $this->model(FreightTemplateRule::class)->where('template_id', $id)->delete();
            return (bool) $template->delete();
        });
    }

    public function updateStatus(int $id, int $status): bool
    {
        $template = $this->model()->find($id);
        if (!$template) {
            throw new BusinessException('运费模板不存在');
        }

        $template->save(['status' => $status]);
        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeRules(array $rules): array
    {
        $result = [];
        $usedStreetIds = [];
        foreach ($rules as $index => $rule) {
            $normalizedRegions = app()->make(RegionResolverService::class)->normalizeStreetSelections((array) ($rule['region_ids'] ?? []));
            foreach ($normalizedRegions['region_ids'] as $streetId) {
                if (in_array($streetId, $usedStreetIds, true)) {
                    throw new BusinessException('同一街道不能重复配置在多个运费规则中');
                }
                $usedStreetIds[] = $streetId;
            }

            $result[] = array_merge($normalizedRegions, [
                'first_amount' => (float) ($rule['first_amount'] ?? 1),
                'first_fee' => (float) ($rule['first_fee'] ?? 0),
                'continue_amount' => (float) ($rule['continue_amount'] ?? 1),
                'continue_fee' => (float) ($rule['continue_fee'] ?? 0),
                'sort' => (int) ($rule['sort'] ?? $index),
            ]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function extractTemplateData(array $data): array
    {
        return [
            'name' => $data['name'],
            'charge_type' => $data['charge_type'],
            'default_first_amount' => $data['default_first_amount'],
            'default_first_fee' => $data['default_first_fee'],
            'default_continue_amount' => $data['default_continue_amount'],
            'default_continue_fee' => $data['default_continue_fee'],
            'status' => $data['status'] ?? 1,
            'remark' => $data['remark'] ?? '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    protected function saveRules(int $templateId, array $rules): void
    {
        foreach ($rules as $rule) {
            $this->model(FreightTemplateRule::class)->create(array_merge($rule, [
                'template_id' => $templateId,
            ]));
        }
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    protected function refreshRuleRegionState(array $rule): array
    {
        $ids = array_map('intval', (array) ($rule['region_ids'] ?? []));
        if ($ids === []) {
            $rule['region_status'] = 0;
            $rule['region_invalid_reason'] = '规则未配置街道';
            return $rule;
        }

        $regions = $this->model(\app\admin\model\region\Region::class)
            ->whereIn('id', $ids)
            ->where('status', 1)
            ->count();

        $valid = $regions === count($ids);
        $rule['region_status'] = $valid ? 1 : 0;
        $rule['region_invalid_reason'] = $valid ? null : '规则包含已失效街道，请重新选择';
        return $rule;
    }
}
