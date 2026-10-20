import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Select, Spin, Typography, message } from 'antd';

export const ALL_USERS = '__all_users__';

export function editorSelectionPayload(values) {
    const allUsers = values.includes(ALL_USERS);
    return { all_users_can_edit: allUsers, editor_ids: allUsers ? [] : values };
}

export default function FileEditorSelect({ value, onChange, disabled, initialUsers }) {
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const handleChange = next => {
        if (next.includes(ALL_USERS) && !value.includes(ALL_USERS)) {
            onChange([ALL_USERS]);
            return;
        }
        const ids = next.filter(id => id !== ALL_USERS);
        if (ids.length > 50) {
            message.warning('Có thể chọn tối đa 50 người hoặc chọn Tất cả người dùng.');
            return;
        }
        onChange(ids);
    };

    useEffect(() => {
        if (initialUsers) setOptions(initialUsers.map(user => ({ value: user.id, label: `${user.name} (${user.employee_code})` })));
    }, [initialUsers]);

    useEffect(() => {
        const controller = new AbortController();
        setError('');
        setLoading(false);
        if (disabled || search.trim().length < 2) return () => controller.abort();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            try {
                const response = await axios.get('/file-editors', { params: { search: search.trim() }, signal: controller.signal });
                if (controller.signal.aborted) return;
                setOptions(previous => Array.from(new Map([
                    ...previous.filter(option => value.includes(option.value)),
                    ...response.data.map(user => ({ value: user.id, label: `${user.name} (${user.employee_code})` })),
                ].map(option => [option.value, option])).values()));
            } catch (requestError) {
                if (!controller.signal.aborted) setError('Không tìm được người dùng. Vui lòng thử lại.');
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 300);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [search, value, disabled]);

    return (
        <div style={{ marginTop: 16, marginBottom: 12 }}>
            <Typography.Text strong style={{ display: 'block', marginBottom: 8 }}>Người được phép chỉnh sửa</Typography.Text>
            <Select
                aria-label="Người được phép chỉnh sửa"
                mode="multiple"
                showSearch
                allowClear
                value={value}
                onChange={handleChange}
                onSearch={setSearch}
                filterOption={false}
                options={[{ value: ALL_USERS, label: 'Tất cả người dùng' }, ...options]}
                loading={loading}
                disabled={disabled}
                placeholder="Chọn tất cả hoặc tìm theo tên/mã nhân viên"
                notFoundContent={loading ? <Spin size="small" /> : error || (search.trim().length < 2 ? 'Nhập ít nhất 2 ký tự để tìm người dùng.' : 'Không tìm thấy người dùng.')}
                style={{ width: '100%' }}
                status={error ? 'error' : undefined}
            />
            {error && <Typography.Text type="danger" style={{ display: 'block', marginTop: 6 }}>{error}</Typography.Text>}
            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 6, fontSize: 12 }}>
                {value.includes(ALL_USERS)
                    ? 'Bất kỳ ai có đường dẫn đều có thể mở và chỉnh sửa tài liệu, kể cả khi chưa đăng nhập.'
                    : 'Bỏ trống: chỉ bạn được sửa. Chọn tất cả hoặc tìm người theo tên/mã nhân viên (ít nhất 2 ký tự).'}
            </Typography.Text>
        </div>
    );
}
