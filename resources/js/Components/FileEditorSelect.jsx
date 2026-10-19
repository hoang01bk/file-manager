import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Select, Spin, Typography } from 'antd';

export default function FileEditorSelect({ value, onChange, disabled }) {
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

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
                onChange={onChange}
                onSearch={setSearch}
                filterOption={false}
                options={options}
                disabled={disabled}
                placeholder="Tìm tên hoặc mã nhân viên (ít nhất 2 ký tự)"
                notFoundContent={loading ? <Spin size="small" /> : error || (search.trim().length < 2 ? 'Nhập ít nhất 2 ký tự để tìm người dùng.' : 'Không tìm thấy người dùng.')}
                style={{ width: '100%' }}
                maxCount={50}
                status={error ? 'error' : undefined}
            />
            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 6, fontSize: 12 }}>
                Bỏ trống: chỉ bạn được sửa. Người được chọn có thể xem, tải và chỉnh sửa Word/Excel sau khi đăng nhập.
            </Typography.Text>
        </div>
    );
}
