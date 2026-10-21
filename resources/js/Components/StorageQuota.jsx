import React from 'react';
import { Progress } from 'antd';
import { CloudOutlined } from '@ant-design/icons';
const mebibyte = 1024 * 1024;

export function formatStorageSize(bytes = 0) {
  return bytes >= 1024 * mebibyte
    ? `${(bytes / (1024 * mebibyte)).toFixed(2)} GB`
    : `${(bytes / mebibyte).toFixed(1)} MB`;
}

export default function StorageQuota({ quota, compact = false }) {
  if (!quota) return null;

  const percent = quota.limit > 0 ? Math.min(100, Math.round(quota.used / quota.limit * 100)) : 100;

  return (
    <div className="storage-quota">
      <div className="storage-quota-heading"><CloudOutlined />{quota.guest ? 'Bộ nhớ dùng chung của khách' : 'Bộ nhớ của bạn'}</div>
      <Progress percent={percent} showInfo={false} size="small" strokeColor={quota.used >= quota.limit ? '#d93025' : '#1a73e8'} />
      <div className="storage-quota-details"><span>Đã dùng {formatStorageSize(quota.used)}</span><span>/ {formatStorageSize(quota.limit)}</span></div>
      {!compact && <span className="storage-quota-caption">Mỗi file tối đa 100 MB. Dung lượng tính cho người tải file lên.</span>}
    </div>
  );
}
