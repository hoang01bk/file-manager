import { Tag } from 'antd';

export const statuses = [
    { value: 'todo', label: 'Mới', color: 'default' },
    { value: 'doing', label: 'Đang làm', color: 'blue' },
    { value: 'blocked', label: 'Tạm dừng', color: 'orange' },
    { value: 'done', label: 'Hoàn thành', color: 'green' },
];
export const priorities = [
    { value: 'low', label: 'Thấp', color: 'default' },
    { value: 'normal', label: 'Bình thường', color: 'blue' },
    { value: 'high', label: 'Cao', color: 'orange' },
    { value: 'urgent', label: 'Khẩn cấp', color: 'red' },
];
export const statusLabel = value => statuses.find(option => option.value === value)?.label || value;
export const priorityLabel = value => priorities.find(option => option.value === value)?.label || value;
export const dateLabel = value => value ? value.slice(0, 10).split('-').reverse().join('/') : 'Chưa đặt hạn';
export const memberOptions = project => project?.members?.map(user => ({ value: user.id, label: `${user.name} (${user.employee_code})` })) || [];
export const failureText = error => {
    const status = error.response?.status;
    if ([401, 419].includes(status)) return 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang và đăng nhập.';
    if (status === 404) return 'Không tìm thấy dữ liệu hoặc bạn không còn quyền truy cập dự án này.';
    if (status === 403) return 'Bạn không có quyền thực hiện thao tác này.';
    return Object.values(error.response?.data?.errors || {}).flat()[0] || error.response?.data?.message || 'Không lưu được dữ liệu. Vui lòng thử lại.';
};
export const isOverdue = (item, today) => Boolean(item.due_date && item.due_date < today && item.status !== 'done');
export function StatusTag({ value }) { return <Tag color={statuses.find(option => option.value === value)?.color}>{statusLabel(value)}</Tag>; }
export function PriorityTag({ value }) { return <Tag color={priorities.find(option => option.value === value)?.color}>{priorityLabel(value)}</Tag>; }
