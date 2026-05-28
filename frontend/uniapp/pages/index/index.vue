<script setup>
import { ref, computed, onMounted } from 'vue'
import { onPullDownRefresh, onReachBottom, onShareAppMessage, onShareTimeline } from '@dcloudio/uni-app'
import { getGoodsList, getGoodsRecommend } from '@/api/goods/goods'
import { useAppStore } from '@/store/app'
import { formatPrice } from '@/utils/price'

const appStore = useAppStore()

const systemInfo = uni.getSystemInfoSync()
const statusBarHeight = systemInfo.statusBarHeight || 0

const recommendList = ref([])
const goodsList = ref([])
const page = ref(1)
const limit = 10
const loading = ref(false)
const noMore = ref(false)
const refreshing = ref(false)

const categoryEntries = [
  { label: '数码', query: '?keyword=数码', icon: 'phone' },
  { label: '美妆', query: '?keyword=美妆', icon: 'beauty' },
  { label: '服饰', query: '?keyword=服饰', icon: 'shirt' },
  { label: '家居', query: '?keyword=家居', icon: 'sofa' },
  { label: '美食', query: '?keyword=美食', icon: 'food' },
]

const banners = computed(() => {
  const raw = appStore.siteConfig?.client_home_banners
  if (Array.isArray(raw) && raw.length > 0) return raw
  return []
})

const mustBuyList = computed(() => {
  const source = recommendList.value.length > 0 ? recommendList.value : goodsList.value
  return source.slice(0, 6)
})

async function fetchRecommend() {
  try {
    const data = await getGoodsRecommend(8)
    recommendList.value = Array.isArray(data?.list)
      ? data.list
      : (Array.isArray(data) ? data : [])
  } catch {
    recommendList.value = []
  }
}

async function fetchGoodsList(reset = false) {
  if (loading.value) return
  if (!reset && noMore.value) return

  loading.value = true

  if (reset) {
    page.value = 1
    noMore.value = false
  }

  try {
    const data = await getGoodsList({ page: page.value, limit })
    const list = Array.isArray(data?.list)
      ? data.list
      : (Array.isArray(data) ? data : [])

    goodsList.value = reset ? list : [...goodsList.value, ...list]

    if (list.length < limit) {
      noMore.value = true
    } else {
      page.value += 1
    }
  } catch {
    if (reset) goodsList.value = []
  } finally {
    loading.value = false
  }
}

async function refresh() {
  refreshing.value = true
  await Promise.all([fetchRecommend(), fetchGoodsList(true)])
  refreshing.value = false
}

onMounted(() => {
  refresh()
})

onPullDownRefresh(async () => {
  await refresh()
  uni.stopPullDownRefresh()
})

onReachBottom(() => {
  fetchGoodsList(false)
})

function getFirstImage(item) {
  if (!item) return ''
  if (item.main_image_full_url) return item.main_image_full_url
  if (item.cover) return item.cover
  if (item.main_image) return item.main_image
  if (Array.isArray(item.images) && item.images.length > 0) {
    const first = item.images[0]
    if (typeof first === 'string') return first
    if (first?.full_url) return first.full_url
    if (first?.url) return first.url
  }
  return ''
}

function goSearch() {
  uni.navigateTo({ url: '/pages-sub/search/index' })
}

function goGoodsDetail(id) {
  if (!id) return
  uni.navigateTo({ url: `/pages-sub/goods/detail?id=${id}` })
}

function goGoodsList(query = '') {
  uni.navigateTo({ url: `/pages-sub/goods/list${query}` })
}

function shareConfig() {
  const config = appStore.siteConfig || {}
  const title = config.client_share_title || config.site_name || 'MallBase'
  const imageUrl = config.client_share_cover || ''
  return { title, imageUrl }
}

onShareAppMessage(() => {
  const { title, imageUrl } = shareConfig()
  return { title, path: '/pages/index/index', imageUrl }
})

onShareTimeline(() => {
  const { title, imageUrl } = shareConfig()
  return { title, imageUrl }
})
</script>

<template>
  <view class="page">
    <mb-splash />
    <view class="home" :style="{ paddingTop: statusBarHeight + 'px' }">
      <view class="header">
        <view class="brand">
          <view class="brand__icon">
            <view class="brand__icon-line" />
          </view>
          <text class="brand__text">MallBase</text>
        </view>
      </view>

      <view class="search" @tap="goSearch">
        <view class="search__icon" />
        <text class="search__text">搜索你心仪的商品...</text>
      </view>

      <view class="hero" @tap="goGoodsList()">
        <swiper
          v-if="banners.length > 0"
          class="hero__swiper"
          :autoplay="true"
          :interval="4200"
          :duration="500"
          :circular="true"
          :indicator-dots="false"
        >
          <swiper-item v-for="(src, idx) in banners" :key="idx">
            <image class="hero__image" :src="src" mode="aspectFill" />
          </swiper-item>
        </swiper>
        <view v-else class="hero__fallback">
          <view class="hero__mall">
            <view class="hero__hall" />
            <view class="hero__shop hero__shop--left" />
            <view class="hero__shop hero__shop--right" />
            <view class="hero__floor" />
          </view>
        </view>
      </view>

      <view class="category-row">
        <view
          v-for="item in categoryEntries"
          :key="item.label"
          class="category-item"
          @tap="goGoodsList(item.query)"
        >
          <view class="category-item__icon">
            <view :class="['cat-icon', `cat-icon--${item.icon}`]" />
          </view>
          <text class="category-item__label">{{ item.label }}</text>
        </view>
      </view>

      <view v-if="mustBuyList.length > 0" class="section">
        <view class="section__head">
          <text class="section__title">今日必买</text>
          <view class="section__more" @tap="goGoodsList('?tag=recommend')">
            <text class="section__more-text">查看全部</text>
            <view class="arrow" />
          </view>
        </view>

        <scroll-view scroll-x class="must-scroll" :show-scrollbar="false">
          <view class="must-scroll__track">
            <view
              v-for="item in mustBuyList"
              :key="item.id"
              class="must-card"
              @tap="goGoodsDetail(item.id)"
            >
              <view class="must-card__image-wrap">
                <image
                  v-if="getFirstImage(item)"
                  class="must-card__image"
                  :src="getFirstImage(item)"
                  mode="aspectFill"
                  lazy-load
                />
                <view v-else class="image-placeholder" />
              </view>
              <text class="must-card__name">{{ item.name }}</text>
              <text class="must-card__price">¥{{ formatPrice(item.price) }}</text>
            </view>
          </view>
        </scroll-view>
      </view>

      <view v-if="goodsList.length > 0" class="section section--goods">
        <view class="section__head">
          <text class="section__title">猜你喜欢</text>
        </view>

        <view class="goods-grid">
          <view
            v-for="(item, index) in goodsList"
            :key="item.id"
            class="goods-card"
            @tap="goGoodsDetail(item.id)"
          >
            <view class="goods-card__image-wrap">
              <image
                v-if="getFirstImage(item)"
                class="goods-card__image"
                :src="getFirstImage(item)"
                mode="aspectFill"
                lazy-load
              />
              <view v-else class="image-placeholder" />
            </view>
            <view class="goods-card__body">
              <text class="goods-card__name">{{ item.name }}</text>
              <view class="goods-card__tags">
                <text class="goods-card__tag">{{ index % 2 === 0 ? '满减中' : '新品' }}</text>
                <text v-if="index % 3 === 0" class="goods-card__tag">顺丰直达</text>
              </view>
              <view class="goods-card__bottom">
                <text class="goods-card__price">¥{{ formatPrice(item.price) }}</text>
                <text class="goods-card__sold">已售 {{ index % 2 === 0 ? '2k+' : '500+' }}</text>
              </view>
            </view>
          </view>
        </view>
      </view>

      <view
        v-if="!loading && !refreshing && goodsList.length === 0 && recommendList.length === 0"
        class="empty"
      >
        <text class="empty__text">暂无商品</text>
      </view>

      <view v-if="goodsList.length > 0" class="load-state">
        <text v-if="loading" class="load-state__text">加载中...</text>
        <text v-else-if="noMore" class="load-state__text">已经到底了</text>
      </view>

      <view class="bottom-spacer" />
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: #faf8ff;
}

.home {
  min-height: 100vh;
  padding-left: 28rpx;
  padding-right: 28rpx;
}

.header {
  height: 72rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10rpx;
}

.brand__icon {
  width: 28rpx;
  height: 26rpx;
  border: 4rpx solid #2b5aed;
  border-radius: 6rpx;
  position: relative;

  &::before {
    content: '';
    position: absolute;
    left: 3rpx;
    right: 3rpx;
    top: 5rpx;
    height: 3rpx;
    background: #2b5aed;
    border-radius: 3rpx;
  }
}

.brand__icon-line {
  position: absolute;
  left: 5rpx;
  top: -8rpx;
  width: 4rpx;
  height: 8rpx;
  background: #2b5aed;
  border-radius: 4rpx;

  &::after {
    content: '';
    position: absolute;
    left: 10rpx;
    top: 0;
    width: 4rpx;
    height: 8rpx;
    background: #2b5aed;
    border-radius: 4rpx;
  }
}

.brand__text {
  font-size: 32rpx;
  line-height: 1;
  font-weight: 800;
  color: #2b5aed;
}

.search {
  height: 72rpx;
  margin-top: 14rpx;
  padding: 0 24rpx;
  border-radius: 12rpx;
  background: #f0f0fb;
  display: flex;
  align-items: center;
}

.search__icon {
  width: 22rpx;
  height: 22rpx;
  border: 4rpx solid #aeb5ca;
  border-radius: 50%;
  position: relative;

  &::after {
    content: '';
    position: absolute;
    right: -10rpx;
    bottom: -6rpx;
    width: 13rpx;
    height: 4rpx;
    border-radius: 4rpx;
    background: #aeb5ca;
    transform: rotate(45deg);
  }
}

.search__text {
  margin-left: 20rpx;
  font-size: 24rpx;
  color: #a6adbf;
}

.hero {
  position: relative;
  height: 314rpx;
  margin-top: 32rpx;
  border-radius: 12rpx;
  overflow: hidden;
  background: #e8e6e2;
}

.hero__swiper,
.hero__image,
.hero__fallback {
  width: 100%;
  height: 100%;
}

.hero__fallback {
  position: relative;
  background: linear-gradient(90deg, #332b25 0%, #d8d1c4 49%, #1c1714 100%);
}

.hero__mall {
  position: absolute;
  inset: 0;
}

.hero__hall {
  position: absolute;
  left: 258rpx;
  top: 0;
  width: 130rpx;
  height: 100%;
  background: linear-gradient(180deg, rgba(250, 252, 255, 0.85), rgba(218, 225, 230, 0.55));
  transform: skewX(-4deg);
}

.hero__shop {
  position: absolute;
  top: 40rpx;
  width: 220rpx;
  height: 176rpx;
  background: rgba(24, 20, 17, 0.68);
}

.hero__shop--left {
  left: 0;
  transform: skewY(6deg);
}

.hero__shop--right {
  right: 0;
  transform: skewY(-6deg);
}

.hero__floor {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 124rpx;
  background: linear-gradient(180deg, rgba(255,255,255,0.18), rgba(226, 222, 214, 0.75));
}

.category-row {
  display: flex;
  justify-content: space-between;
  margin-top: 40rpx;
  padding: 0 6rpx;
}

.category-item {
  width: 104rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.category-item__icon {
  width: 76rpx;
  height: 76rpx;
  border-radius: 16rpx;
  background: #f0f6ff;
  display: flex;
  align-items: center;
  justify-content: center;
}

.category-item__label {
  margin-top: 14rpx;
  font-size: 22rpx;
  font-weight: 700;
  color: #51566a;
}

.cat-icon {
  position: relative;
  width: 34rpx;
  height: 34rpx;
  color: #2b5aed;
}

.cat-icon--phone {
  border: 4rpx solid #2b5aed;
  border-radius: 5rpx;

  &::after {
    content: '';
    position: absolute;
    left: 9rpx;
    bottom: 3rpx;
    width: 8rpx;
    height: 3rpx;
    background: #2b5aed;
    border-radius: 3rpx;
  }
}

.cat-icon--beauty {
  border-left: 5rpx solid #2b5aed;
  border-right: 5rpx solid #2b5aed;
  border-bottom: 5rpx solid #2b5aed;
  border-radius: 0 0 10rpx 10rpx;

  &::before {
    content: '';
    position: absolute;
    left: 6rpx;
    top: -12rpx;
    width: 12rpx;
    height: 18rpx;
    border: 4rpx solid #2b5aed;
    border-radius: 12rpx 12rpx 0 0;
  }
}

.cat-icon--shirt {
  &::before {
    content: '';
    position: absolute;
    inset: 2rpx 3rpx;
    border: 5rpx solid #2b5aed;
    border-top: 0;
    border-radius: 5rpx;
  }

  &::after {
    content: '';
    position: absolute;
    left: 9rpx;
    top: 0;
    width: 14rpx;
    height: 10rpx;
    border-bottom: 5rpx solid #2b5aed;
    border-radius: 0 0 10rpx 10rpx;
  }
}

.cat-icon--sofa {
  border: 5rpx solid #2b5aed;
  border-radius: 7rpx;

  &::after {
    content: '';
    position: absolute;
    left: -6rpx;
    right: -6rpx;
    bottom: -6rpx;
    height: 12rpx;
    border: 5rpx solid #2b5aed;
    border-radius: 8rpx;
    background: #f0f6ff;
  }
}

.cat-icon--food {
  &::before {
    content: '';
    position: absolute;
    left: 7rpx;
    top: 1rpx;
    width: 5rpx;
    height: 32rpx;
    background: #2b5aed;
    border-radius: 5rpx;
  }

  &::after {
    content: '';
    position: absolute;
    right: 7rpx;
    top: 0;
    width: 10rpx;
    height: 34rpx;
    border-left: 5rpx solid #2b5aed;
    border-radius: 10rpx;
  }
}

.section {
  margin-top: 38rpx;
}

.section--goods {
  margin-top: 34rpx;
}

.section__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 22rpx;
}

.section__title {
  font-size: 32rpx;
  font-weight: 800;
  color: #191b23;
}

.section__more {
  display: flex;
  align-items: center;
  gap: 6rpx;
}

.section__more-text {
  font-size: 22rpx;
  font-weight: 700;
  color: #2b5aed;
}

.arrow {
  width: 10rpx;
  height: 10rpx;
  border-top: 3rpx solid #2b5aed;
  border-right: 3rpx solid #2b5aed;
  transform: rotate(45deg);
}

.must-scroll {
  width: 100%;
  white-space: nowrap;
}

.must-scroll__track {
  display: inline-flex;
  gap: 20rpx;
  padding: 0 4rpx 8rpx;
}

.must-card {
  width: 238rpx;
  padding: 12rpx;
  border-radius: 12rpx;
  background: #ffffff;
  display: inline-flex;
  flex-direction: column;
}

.must-card__image-wrap {
  width: 214rpx;
  height: 214rpx;
  border-radius: 10rpx;
  overflow: hidden;
  background: #f2f3f8;
}

.must-card__image,
.image-placeholder {
  width: 100%;
  height: 100%;
}

.image-placeholder {
  background: linear-gradient(135deg, #edf2ff, #f8f8ff);
}

.must-card__name {
  margin-top: 14rpx;
  font-size: 22rpx;
  font-weight: 700;
  color: #3b3f4c;
  line-height: 1.35;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.must-card__price {
  margin-top: 8rpx;
  font-size: 34rpx;
  line-height: 1;
  font-weight: 900;
  color: #1453d8;
}

.goods-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20rpx;
}

.goods-card {
  min-width: 0;
  overflow: hidden;
  border-radius: 12rpx;
  background: #ffffff;
}

.goods-card__image-wrap {
  width: 100%;
  height: 278rpx;
  background: #f2f3f8;
}

.goods-card__image {
  width: 100%;
  height: 100%;
}

.goods-card__body {
  padding: 20rpx 18rpx 18rpx;
}

.goods-card__name {
  min-height: 72rpx;
  font-size: 24rpx;
  font-weight: 800;
  color: #3b3f4c;
  line-height: 1.45;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.goods-card__tags {
  min-height: 36rpx;
  margin-top: 12rpx;
  display: flex;
  gap: 8rpx;
  flex-wrap: wrap;
}

.goods-card__tag {
  height: 32rpx;
  padding: 0 10rpx;
  border-radius: 4rpx;
  background: #eef4ff;
  color: #2b5aed;
  font-size: 18rpx;
  font-weight: 800;
  line-height: 32rpx;
}

.goods-card__bottom {
  margin-top: 14rpx;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10rpx;
}

.goods-card__price {
  font-size: 34rpx;
  font-weight: 900;
  color: #1453d8;
  line-height: 1;
}

.goods-card__sold {
  min-width: 0;
  font-size: 20rpx;
  color: #c4c8d4;
  white-space: nowrap;
}

.empty {
  height: 420rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}

.empty__text,
.load-state__text {
  font-size: 24rpx;
  color: #737686;
}

.load-state {
  padding: 40rpx 0 20rpx;
  text-align: center;
}

.bottom-spacer {
  height: 160rpx;
}
</style>
