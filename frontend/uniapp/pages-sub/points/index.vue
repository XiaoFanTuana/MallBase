<script setup>
import { useDecorateStore } from "@/store/decorate";
import { computed, ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { getPointsInfo, getPointsLogs } from "@/api/points/points";
import { isPointsEnabled, leavePointsPage } from "@/utils/points-feature";

const decorateStore = useDecorateStore();

const loading = ref(false);
const points = ref({
  balance_points: 0,
  total_income_points: 0,
  total_expense_points: 0,
  month_income_points: 0,
  month_expense_points: 0,
});
const recentLogs = ref([]);
const pointsEnabled = ref(true);

const balanceText = computed(() => Number(points.value.balance_points || 0));
const monthIncomeText = computed(() =>
  Number(points.value.month_income_points || 0),
);
const monthExpenseText = computed(() =>
  Number(points.value.month_expense_points || 0),
);

onShow(async () => {
  if (await ensurePointsEnabled()) {
    fetchPoints();
  }
});

async function ensurePointsEnabled() {
  pointsEnabled.value = await isPointsEnabled();
  if (!pointsEnabled.value) {
    leavePointsPage();
    return false;
  }
  return true;
}

async function fetchPoints() {
  loading.value = true;
  try {
    const data = await getPointsInfo();
    points.value = {
      ...points.value,
      ...(data || {}),
    };
  } catch {
    points.value = {
      balance_points: 0,
      total_income_points: 0,
      total_expense_points: 0,
      month_income_points: 0,
      month_expense_points: 0,
    };
  }

  try {
    const data = await getPointsLogs({ page: 1, limit: 3 });
    recentLogs.value = Array.isArray(data?.list) ? data.list : [];
  } catch {
    recentLogs.value = [];
  } finally {
    loading.value = false;
  }
}

function signedPoints(item) {
  const value = Number(item.change_points || 0);
  const direction = String(item.direction || "");
  const isIncome = direction === "income";
  return `${isIncome ? "+" : "-"}${Math.abs(value)}`;
}

function logTitle(item) {
  return item.title || item.biz_type_text || item.remark || "积分变动";
}

function logTime(item) {
  return item.create_time || item.time || "";
}

function goRecords(params = {}) {
  const query = Object.entries(params)
    .filter(([, value]) => value !== undefined && value !== "")
    .map(([key, value]) => `${key}=${encodeURIComponent(value)}`)
    .join("&");
  uni.navigateTo({
    url: `/pages-sub/points/records${query ? `?${query}` : ""}`,
  });
}

function goMall() {
  uni.navigateTo({ url: "/pages-sub/points/mall" });
}

function goExchangeOrders() {
  uni.navigateTo({ url: "/pages-sub/points/exchange-orders" });
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="我的积分" bg-color="var(--color-bg, #ffffff)" />

    <view class="points-card">
      <view class="points-card__top">
        <text class="points-card__label">可用积分</text>
        <text class="points-card__status">{{
          loading ? "同步中" : "账户正常"
        }}</text>
      </view>
      <view class="points-card__amount">
        <text class="points-card__value">{{ balanceText }}</text>
        <text class="points-card__unit">积分</text>
      </view>
      <view class="points-card__stats">
        <view class="points-stat">
          <text class="points-stat__label">本月获得</text>
          <text class="points-stat__value">+{{ monthIncomeText }}</text>
        </view>
        <view class="points-stat">
          <text class="points-stat__label">本月使用</text>
          <text class="points-stat__value">-{{ monthExpenseText }}</text>
        </view>
      </view>
    </view>

    <view class="action-grid">
      <view class="action-item action-item--primary" @tap="goMall">
        <text class="action-item__label">积分商城</text>
      </view>
      <view class="action-item" @tap="goExchangeOrders">
        <text class="action-item__label">兑换记录</text>
      </view>
      <view class="action-item" @tap="goRecords()">
        <text class="action-item__label">积分明细</text>
      </view>
      <view
        class="action-item"
        @tap="goRecords({ biz_type: 'order_complete', type: 'income' })"
      >
        <text class="action-item__label">获得记录</text>
      </view>
      <view class="action-item" @tap="goRecords({ type: 'expense' })">
        <text class="action-item__label">使用记录</text>
      </view>
      <view
        class="action-item"
        @tap="goRecords({ biz_type: 'refund', type: 'expense' })"
      >
        <text class="action-item__label">回收记录</text>
      </view>
    </view>

    <view class="section">
      <view class="section__header">
        <text class="section__title">最近记录</text>
        <text class="section__more" @tap="goRecords()">查看全部</text>
      </view>

      <view v-if="recentLogs.length" class="log-list">
        <view
          v-for="item in recentLogs"
          :key="item.id || item.create_time"
          class="log-row"
        >
          <view class="log-row__icon">
            <text class="log-row__icon-text">P</text>
          </view>
          <view class="log-row__main">
            <text class="log-row__title">{{ logTitle(item) }}</text>
            <text class="log-row__time">{{ logTime(item) }}</text>
          </view>
          <view class="log-row__right">
            <text
              class="log-row__amount"
              :class="{
                'log-row__amount--income': signedPoints(item).startsWith('+'),
              }"
            >
              {{ signedPoints(item) }}
            </text>
            <text
              v-if="item.after_points !== undefined"
              class="log-row__balance"
            >
              余额 {{ item.after_points }}
            </text>
          </view>
        </view>
      </view>

      <view v-else class="empty">
        <text class="empty__icon">P</text>
        <text class="empty__title">暂无积分记录</text>
        <text class="empty__desc">订单完成后会显示在这里</text>
      </view>
    </view>
</view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding: 0 $mb-spacing-page 48rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.points-card {
  margin-top: $mb-spacing-md;
  padding: 32rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.points-card__top,
.points-card__stats,
.section__header,
.log-row {
  display: flex;
  align-items: center;
}

.points-card__top,
.section__header {
  justify-content: space-between;
}

.points-card__label,
.section__title {
  color: var(--color-text, #111827);
  font-size: 28rpx;
  font-weight: 700;
}

.points-card__status,
.section__more,
.points-stat__label,
.log-row__time,
.log-row__balance,
.empty__desc {
  color: var(--color-text-muted, #6b7280);
  font-size: 24rpx;
}

.points-card__amount {
  display: flex;
  align-items: baseline;
  gap: 12rpx;
  margin-top: 28rpx;
}

.points-card__value {
  color: var(--color-primary, #0d50d5);
  font-size: 72rpx;
  font-weight: 800;
  line-height: 1;
}

.points-card__unit {
  color: var(--color-text-secondary, #4b5563);
  font-size: 26rpx;
  font-weight: 600;
}

.points-card__stats {
  gap: 20rpx;
  margin-top: 28rpx;
}

.points-stat {
  flex: 1;
  padding: 20rpx;
  background: var(--color-bg-surface, #f8fafc);
  border-radius: $mb-radius-md;
}

.points-stat__value {
  display: block;
  margin-top: 8rpx;
  color: var(--color-text, #111827);
  font-size: 30rpx;
  font-weight: 700;
}

.action-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16rpx;
  margin-top: 20rpx;
}

.action-item {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 78rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-md;
}

.action-item--primary {
  background: var(--color-primary, #0d50d5);
  border-color: var(--color-primary, #0d50d5);
}

.action-item__label {
  color: var(--color-text, #111827);
  font-size: 26rpx;
  font-weight: 700;
}

.action-item--primary .action-item__label {
  color: #ffffff;
}

.section {
  margin-top: 24rpx;
  padding: 28rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.log-list {
  margin-top: 18rpx;
}

.log-row {
  gap: 18rpx;
  padding: 20rpx 0;
  border-bottom: 1rpx solid var(--color-divider, #f0f2f5);
}

.log-row:last-child {
  border-bottom: none;
}

.log-row__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 58rpx;
  height: 58rpx;
  background: var(--color-primary-light, #386bef);
  border-radius: 50%;
}

.log-row__icon-text {
  color: #ffffff;
  font-size: 24rpx;
  font-weight: 800;
}

.log-row__main {
  flex: 1;
  min-width: 0;
}

.log-row__title {
  display: block;
  color: var(--color-text, #111827);
  font-size: 27rpx;
  font-weight: 700;
}

.log-row__time {
  display: block;
  margin-top: 6rpx;
}

.log-row__right {
  text-align: right;
}

.log-row__amount {
  display: block;
  color: #f97316;
  font-size: 28rpx;
  font-weight: 800;
}

.log-row__amount--income {
  color: #16a34a;
}

.empty {
  display: flex;
  align-items: center;
  flex-direction: column;
  padding: 54rpx 0;
  text-align: center;
}

.empty__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 72rpx;
  height: 72rpx;
  color: #ffffff;
  font-size: 32rpx;
  font-weight: 800;
  background: var(--color-primary, #0d50d5);
  border-radius: 50%;
}

.empty__title {
  margin-top: 18rpx;
  color: var(--color-text, #111827);
  font-size: 28rpx;
  font-weight: 700;
}

.empty__desc {
  margin-top: 8rpx;
}
</style>
