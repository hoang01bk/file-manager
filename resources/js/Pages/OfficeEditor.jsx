import React, { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { Alert, Button, Space, Spin, Typography } from 'antd';
import { ArrowLeftOutlined, EditOutlined } from '@ant-design/icons';

export default function OfficeEditor({ fileName, editorConfig, scriptUrl, backUrl, editing, editUrl, expiresAt }) {
    const editor = useRef(null);
    const [error, setError] = useState('');
    const [ready, setReady] = useState(false);
    const [expired, setExpired] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [saveError, setSaveError] = useState(false);

    useEffect(() => {
        const warn = (event) => {
            if (dirty || saveError) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty, saveError]);

    useEffect(() => {
        let cancelled = false;
        let loadTimeout;
        const script = document.createElement('script');
        script.src = scriptUrl;
        script.async = true;
        script.onerror = () => setError('Không kết nối được ONLYOFFICE. Vui lòng kiểm tra dịch vụ và thử tải lại trang.');
        script.onload = () => {
            if (cancelled || Date.now() >= Date.parse(expiresAt)) return;
            if (!window.DocsAPI) {
                setError('Không tải được trình chỉnh sửa ONLYOFFICE.');
                return;
            }
            editor.current = new window.DocsAPI.DocEditor('office-editor', {
                ...editorConfig,
                events: {
                    onDocumentReady: () => { clearTimeout(loadTimeout); setReady(true); setError(''); },
                    onDocumentStateChange: (event) => setDirty(Boolean(event.data)),
                    onError: () => {
                        setSaveError(true);
                        setError('ONLYOFFICE gặp lỗi. Hãy giữ trang đang mở và kiểm tra kết nối trước khi đóng tài liệu.');
                    },
                },
            });
        };
        loadTimeout = window.setTimeout(() => setError('Tài liệu đang tải lâu hơn dự kiến. Kiểm tra dịch vụ ONLYOFFICE hoặc thử tải lại trang.'), 60000);
        document.head.appendChild(script);
        const checkExpiry = () => {
            if (Date.now() >= Date.parse(expiresAt)) {
                editor.current?.destroyEditor();
                editor.current = null;
                setExpired(true);
            }
        };
        checkExpiry();
        const interval = window.setInterval(checkExpiry, 1000);
        return () => {
            cancelled = true;
            clearTimeout(loadTimeout);
            window.clearInterval(interval);
            editor.current?.destroyEditor();
            editor.current = null;
            script.remove();
        };
    }, [editorConfig, scriptUrl, expiresAt]);

    return (
        <div style={{ height: '100dvh', display: 'flex', flexDirection: 'column', background: '#f0f2f5' }}>
            <Head title={`${editing ? 'Chỉnh sửa' : 'Xem'} ${fileName}`} />
            <div style={{ background: '#fff', padding: '10px 16px', display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                <Space>
                    <Button href={backUrl} icon={<ArrowLeftOutlined />}>Quay lại</Button>
                    <Typography.Text strong>{fileName}</Typography.Text>
                    <Typography.Text type="secondary">{editing ? 'Chỉnh sửa' : 'Chỉ xem'}</Typography.Text>
                    {editUrl && !expired && <Button type="primary" href={editUrl} icon={<EditOutlined />}>Chỉnh sửa</Button>}
                </Space>
                <Typography.Text type="secondary">
                    {editing ? 'Nhấn Ctrl+S để lưu. ' : ''}Phiên kết thúc: {new Date(expiresAt).toLocaleString('vi-VN')}
                </Typography.Text>
            </div>
            {expired ? (
                <Alert type="warning" showIcon title="Phiên làm việc hoặc file đã hết hạn. Mở lại từ danh sách nếu file còn hiệu lực." />
            ) : (
                <>
                    {error && <Alert type="error" showIcon title={error} />}
                    {!ready && !error && <div style={{ padding: 16, textAlign: 'center' }}><Spin /> Đang mở tài liệu…</div>}
                    <div style={{ flex: 1, minHeight: 0 }}><div id="office-editor" style={{ height: '100%' }} /></div>
                </>
            )}
        </div>
    );
}
