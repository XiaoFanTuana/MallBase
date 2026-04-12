/**
 * 文件上传配置
 */
const imageAcceptTypes = [
  'image/jpeg',
  'image/jpg',
  'image/png',
  'image/gif',
  'image/webp',
];

const documentAcceptTypes = [
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.ms-excel',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'application/vnd.ms-powerpoint',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'application/zip',
  'application/x-zip-compressed',
  'application/vnd.rar',
  'application/x-rar',
  'application/x-rar-compressed',
  'application/x-7z-compressed',
  'application/x-tar',
  'application/gzip',
  'text/plain',
  'text/csv',
  'application/csv',
  'audio/mpeg',
  'audio/mp3',
];

const videoAcceptTypes = [
  'video/mp4',
  'video/quicktime',
  'video/x-msvideo',
  'video/x-matroska',
  'video/x-flv',
  'video/x-ms-wmv',
  'video/webm',
  'video/mp2t',
];

export const uploadConfig = {
  // 图片上传配置
  image: {
    maxSize: 2, // 最大文件大小（MB）
    acceptTypes: imageAcceptTypes,
    maxCount: 1, // 最大上传数量
  },

  // 头像上传配置
  avatar: {
    maxSize: 2, // 最大文件大小（MB）
    acceptTypes: imageAcceptTypes,
    maxCount: 1, // 最大上传数量
  },

  // 多图上传配置
  images: {
    maxSize: 5, // 最大文件大小（MB）
    acceptTypes: imageAcceptTypes,
    maxCount: 9, // 最大上传数量
  },

  // 文件上传配置
  file: {
    maxSize: 10, // 最大文件大小（MB）
    acceptTypes: documentAcceptTypes,
    maxCount: 1, // 最大上传数量
  },

  // 多文件上传配置
  files: {
    maxSize: 10, // 最大文件大小（MB）
    acceptTypes: documentAcceptTypes,
    maxCount: 5, // 最大上传数量
  },

  // 视频上传配置
  video: {
    maxSize: 200, // 最大文件大小（MB）
    acceptTypes: videoAcceptTypes,
    maxCount: 1, // 最大上传数量
  },

  // 多视频上传配置
  videos: {
    maxSize: 200, // 最大文件大小（MB）
    acceptTypes: videoAcceptTypes,
    maxCount: 5, // 最大上传数量
  },
};

/**
 * 获取上传配置
 * @param type 上传类型
 */
export function getUploadConfig(type: keyof typeof uploadConfig) {
  return uploadConfig[type];
}
