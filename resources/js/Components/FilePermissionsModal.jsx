import React, { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Alert, Checkbox, Modal, Spin, Typography, message } from 'antd';
import FileEditorSelect, { ALL_USERS, editorSelectionPayload } from './FileEditorSelect';

export default function FilePermissionsModal({ file, scope, onClose }) {
    const [settings, setSettings] = useState(null);
    const [editorIds, setEditorIds] = useState([]);
    const [viewOnly, setViewOnly] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const ready = settings?.fileId === file?.id && settings?.scope === scope;

    useEffect(() => {
        if (!file) return;
        const controller = new AbortController();
        setSettings(null);
        setError('');
        axios.get(`/office/${scope}/${file.id}/permissions`, { signal: controller.signal })
            .then(({ data }) => {
                if (controller.signal.aborted) return;
                setEditorIds(data.all_users_can_edit ? [ALL_USERS] : data.editors.map(user => user.id));
                setViewOnly(Boolean(data.view_only));
                setSettings({ ...data, fileId: file.id, scope });
            })
            .catch(requestError => {
                if (!controller.signal.aborted) setError(requestError.response?.status === 410 ? 'File đã hết hạn.' : 'Không tải được quyền truy cập. Vui lòng tải lại trang và thử lại.');
            });
        return () => controller.abort();
    }, [file, scope]);

    const save = async () => {
        setSaving(true);
        setError('');
        try {
            await axios.patch(`/office/${scope}/${file.id}/permissions`, {
                ...editorSelectionPayload(editorIds),
                ...(scope === 'shared' ? { view_only: viewOnly } : {}),
            });
            message.success('Đã cập nhật quyền truy cập.');
            onClose();
            router.reload({ only: ['files', 'stats'], preserveScroll: true });
        } catch (requestError) {
            const validationErrors = requestError.response?.data?.errors;
            setError(validationErrors ? Object.values(validationErrors).flat()[0] : 'Không cập nhật được quyền truy cập. Vui lòng tải lại trang và thử lại.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            title="Quyền truy cập"
            open={Boolean(file)}
            onCancel={() => !saving && onClose()}
            onOk={save}
            okText="Lưu thay đổi"
            cancelText="Hủy"
            confirmLoading={saving}
            okButtonProps={{ disabled: !ready }}
            cancelButtonProps={{ disabled: saving }}
            closable={!saving}
            maskClosable={!saving}
            destroyOnClose
        >
            <Typography.Text strong>{file?.file_name}</Typography.Text>
            {error && <Alert style={{ marginTop: 12 }} type="error" showIcon title={error} />}
            {!ready ? (!error && <div style={{ padding: 24, textAlign: 'center' }}><Spin /></div>) : (
                <>
                    <FileEditorSelect value={editorIds} onChange={setEditorIds} initialUsers={settings.editors} disabled={saving} />
                    {scope === 'shared' ? (
                        <Checkbox checked={viewOnly} onChange={event => setViewOnly(event.target.checked)} disabled={saving}>
                            Chặn tải file đối với người không được cấp quyền
                        </Checkbox>
                    ) : (
                        <Typography.Text type="secondary">{editorIds.includes(ALL_USERS) ? 'Bất kỳ ai có đường dẫn đều có thể mở và chỉnh sửa tài liệu, kể cả khi chưa đăng nhập.' : 'Chỉ bạn và người được chọn có thể xem, tải và chỉnh sửa file cá nhân.'}</Typography.Text>
                    )}
                    <Typography.Paragraph type="secondary" style={{ marginTop: 12, marginBottom: 0 }}>
                        Người bị bỏ khỏi danh sách sẽ không được mở để sửa hoặc lưu thay đổi mới. Người đang mở tài liệu cần tải lại trang để cập nhật các nút quyền truy cập.
                    </Typography.Paragraph>
                </>
            )}
        </Modal>
    );
}
