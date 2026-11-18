import { useState } from 'react';
import axios from 'axios';
import { Alert, Input, Modal, Select } from 'antd';

const types = {
    docx: { label: 'tài liệu Word', name: 'Tài liệu mới' },
    xlsx: { label: 'bảng tính Excel', name: 'Bảng tính mới' },
    pptx: { label: 'bản trình chiếu PowerPoint', name: 'Bản trình chiếu mới' },
};

export default function CreateOfficeFileModal({ type, scope, folder, ttlOptions, onClose, onCreated }) {
    const info = types[type];
    const [name, setName] = useState(info.name);
    const [ttl, setTtl] = useState(1440);
    const [creating, setCreating] = useState(false);
    const [error, setError] = useState('');

    const create = async () => {
        if (creating) return;
        if (!name.trim()) { setError('Vui lòng nhập tên file.'); return; }
        setCreating(true);
        setError('');
        try {
            const { data } = await axios.post(`/office/${scope}/create`, {
                type, name: name.trim(), folder_id: folder?.id ?? null,
                ...(scope === 'shared' ? { ttl_minutes: ttl } : {}),
            });
            onCreated(data.edit_url);
        } catch (failure) {
            setError([401, 419].includes(failure.response?.status) ? 'Vui lòng tải lại trang và đăng nhập để tạo tài liệu.'
                : Object.values(failure.response?.data?.errors || {}).flat()[0] || failure.response?.data?.message || 'Không tạo được file. Vui lòng thử lại.');
            setCreating(false);
        }
    };

    return <Modal title={`Tạo ${info.label}`} open onCancel={() => !creating && onClose()} onOk={create}
        okText="Tạo và mở" cancelText="Hủy" confirmLoading={creating}
        cancelButtonProps={{ disabled: creating }} closable={!creating} maskClosable={!creating} keyboard={!creating}>
        <p>Vị trí: <strong>{folder?.name || (scope === 'personal' ? 'File cá nhân' : 'File Sharing')}</strong></p>
        <label className="workspace-field-label" htmlFor="new-office-file-name">Tên file</label>
        <Input id="new-office-file-name" autoFocus value={name} maxLength={250} onChange={event => setName(event.target.value)}
            suffix={`.${type}`} disabled={creating} onPressEnter={create} />
        {scope === 'shared' && <div style={{ marginTop: 16 }}>
            <label className="workspace-field-label" htmlFor="new-office-file-ttl">Tự động xóa file sau</label>
            <Select id="new-office-file-ttl" value={ttl} onChange={setTtl} options={ttlOptions} disabled={creating} style={{ width: '100%' }} />
        </div>}
        {error && <Alert type="error" title={error} showIcon style={{ marginTop: 16 }} />}
    </Modal>;
}
