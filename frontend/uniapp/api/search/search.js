import { get, post } from '@/api/request'

export const getHotSearch = (limit = 10) => get('/client/api/search/hot', { limit })

export const recordSearch = (keyword, platform) => post('/client/api/search/log', { keyword, platform })
