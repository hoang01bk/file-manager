import React, { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Alert, Button, Input, Modal, Typography, Upload, message } from 'antd';
import { ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, FilePdfOutlined, PlusOutlined } from '@ant-design/icons';
import { formatStorageSize } from './StorageQuota';

const maxBytes = 100 * 1024 * 1024;

export default function PdfMergeModal({ file, scope, onClose }) {
    const [name, setName] = useState('');
    const [files, setFiles] = useState([]);
    const [saving, setSaving] = useState(false);
    const [progress, setProgress] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!file) return;
        setName(`${file.file_name.replace(/\.pdf$/i, '').slice(0, 175)} - đã ghép.pdf`);
        setFiles([]);
        setError('');
        setProgress(null);
    }, [file]);

    const total = files.reduce((sum, item) => sum + (item.size || 0), 0);
    const move = (index, direction) => setFiles(current => {
        const next = [...current];
        [next[index], next[index + direction]] = [next[index + direction], next[index]];
        return next;
    });

    const merge = async () => {
        setSaving(true);
        setError('');
        setProgress(null);
        const body = new FormData();
        body.append('name', name.trim());
        files.forEach(item => body.append('pdfs[]', item.originFileObj || item));
        try {
            await axios.post(`/pdf/${scope}/${file.id}/merge`, body, {
                onUploadProgress: event => setProgress(event.total ? Math.round(event.loaded * 100 / event.total) : null),
            });
            message.success('Đã tạo PDF mới. File gốc được giữ nguyên.');
            onClose();
            router.reload({ only: ['files', 'stats', 'storageQuota'], preserveScroll: true });
        } catch (requestError) {
            const errors = requestError.response?.data?.errors;
            const status = requestError.response?.status;
            setError(errors ? Object.values(errors).flat()[0]
                : status === 403 ? 'Bạn không còn quyền ghép file này hoặc phiên đăng nhập đã thay đổi.'
                    : status === 410 ? 'File gốc đã hết hạn.'
                        : status === 404 ? 'Không tìm thấy file gốc.'
                            : status === 413 ? 'Tổng dung lượng tải thêm không được vượt quá 100 MB.'
                                : status === 503 ? 'Chức năng ghép PDF chưa sẵn sàng trên máy chủ.'
                                    : 'Không ghép được PDF. Vui lòng thử lại.');
        } finally {
            setSaving(false);
            setProgress(null);
        }
    };

    return (
        <Modal title="Ghép PDF" open={Boolean(file)} width={580}
            onCancel={() => !saving && onClose()} onOk={merge}
            okText={saving ? (progress !== null && progress < 100 ? `Đang tải lên ${progress}%` : 'Đang ghép…') : 'Tạo PDF mới'}
            cancelText="Hủy" confirmLoading={saving}
            okButtonProps={{ disabled: !files.length || !name.trim() || total > maxBytes }}
            cancelButtonProps={{ disabled: saving }} closable={!saving} maskClosable={!saving} keyboard={!saving}>
            <div className="workspace-pdf-merge">
                <Typography.Paragraph type="secondary">Giữ nguyên PDF gốc và nối các trang mới vào cuối để tạo một PDF khác.</Typography.Paragraph>
                <div className="workspace-pdf-merge-original"><FilePdfOutlined /><div><strong>{file?.file_name}</strong><small>File đầu tiên · Giữ nguyên thứ tự trang</small></div></div>
                <label className="workspace-field-label" htmlFor="merged-pdf-name">Tên PDF mới</label>
                <Input id="merged-pdf-name" value={name} onChange={event => setName(event.target.value)} maxLength={200} disabled={saving} />
                <label className="workspace-field-label">PDF ghép thêm</label>
                <Upload.Dragger accept=".pdf,application/pdf" multiple maxCount={5} fileList={files}
                    disabled={saving} showUploadList={false} onChange={({ fileList }) => setFiles(fileList)}
                    beforeUpload={item => {
                        if (!/\.pdf$/i.test(item.name)) { message.error('Chỉ hỗ trợ file PDF.'); return Upload.LIST_IGNORE; }
                        if (item.size > maxBytes) { message.error('Mỗi PDF không được vượt quá 100 MB.'); return Upload.LIST_IGNORE; }
                        return false;
                    }}>
                    <PlusOutlined /><p>Chọn hoặc kéo thả PDF vào đây</p><small>Tối đa 5 PDF · Tổng dung lượng tải thêm tối đa 100 MB</small>
                </Upload.Dragger>
                {files.length > 0 && <ol className="workspace-pdf-merge-list">{files.map((item, index) => (
                    <li key={item.uid}>
                        <div><strong>{index + 2}. {item.name}</strong><small>{formatStorageSize(item.size)}</small></div>
                        <Button size="small" type="text" icon={<ArrowUpOutlined />} aria-label={`Đưa ${item.name} lên trên`} disabled={saving || index === 0} onClick={() => move(index, -1)} />
                        <Button size="small" type="text" icon={<ArrowDownOutlined />} aria-label={`Đưa ${item.name} xuống dưới`} disabled={saving || index === files.length - 1} onClick={() => move(index, 1)} />
                        <Button size="small" type="text" icon={<DeleteOutlined />} aria-label={`Bỏ ${item.name}`} disabled={saving} onClick={() => setFiles(current => current.filter(selected => selected.uid !== item.uid))} />
                    </li>
                ))}</ol>}
                {total > maxBytes && <Alert type="error" showIcon title="Tổng dung lượng tải thêm không được vượt quá 100 MB." />}
                {error && <Alert type="error" showIcon title={error} />}
                <Typography.Paragraph type="secondary" className="workspace-pdf-merge-note">PDF mới tối đa 100 MB và tính vào dung lượng lưu trữ của bạn. Quyền truy cập và hạn dùng được giữ theo file gốc.</Typography.Paragraph>
            </div>
        </Modal>
    );
}
