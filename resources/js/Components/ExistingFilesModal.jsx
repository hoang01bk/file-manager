import { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Input, Modal, Table, message } from 'antd';
import { FileOutlined } from '@ant-design/icons';
import { formatStorageSize } from './StorageQuota';

export default function ExistingFilesModal({ scope, folder, onClose, onComplete }) {
    const [files, setFiles] = useState([]);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [selected, setSelected] = useState([]);
    const [loading, setLoading] = useState(true);
    const [moving, setMoving] = useState(false);
    const [error, setError] = useState('');
    const endpoint = `/folders/${scope}/${folder.id}/files`;

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError('');
        axios.get(endpoint, { params: { q: search, page }, signal: controller.signal })
            .then(({ data }) => { setFiles(data.data); setTotal(data.total); })
            .catch(() => { if (!controller.signal.aborted) setError('Không tải được danh sách file. Vui lòng đóng và mở lại để thử lại.'); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [endpoint, search, page]);

    const add = async () => {
        if (!selected.length || moving) return;
        setMoving(true);
        setError('');
        try {
            const { data } = await axios.post(endpoint, { file_ids: selected });
            message.success(`Đã chuyển ${data.moved} file vào thư mục “${folder.name}”.`);
            onComplete();
        } catch (failure) {
            setError(failure.response?.status === 419 ? 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.'
                : Object.values(failure.response?.data?.errors || {}).flat()[0] || failure.response?.data?.message || 'Không chuyển được file. Vui lòng thử lại.');
        } finally {
            setMoving(false);
        }
    };

    return <Modal title="Thêm file đã upload" open onCancel={() => !moving && onClose()} onOk={add}
        okText="Thêm vào thư mục" cancelText="Hủy" confirmLoading={moving}
        okButtonProps={{ disabled: !selected.length || loading }} cancelButtonProps={{ disabled: moving }}
        closable={!moving} maskClosable={!moving} keyboard={!moving} width={760}>
        <p>Các file đã chọn sẽ được chuyển vào thư mục <strong>{folder.name}</strong>. Nội dung, quyền truy cập và thời hạn lưu được giữ nguyên.</p>
        <Input.Search aria-label="Tìm file đã upload" placeholder="Tìm theo tên file" enterButton="Tìm" allowClear maxLength={200}
            disabled={moving} onSearch={value => { setSearch(value.trim()); setPage(1); }} />
        {error && <Alert type="error" title={error} showIcon style={{ marginTop: 12 }} />}
        <Table rowKey="id" dataSource={files} loading={loading} size="small" style={{ marginTop: 16 }} scroll={{ x: 580 }}
            columns={[
                { title: 'Tên file', dataIndex: 'file_name', render: name => <span className="workspace-existing-file-name"><FileOutlined /><span>{name}</span></span> },
                { title: 'Vị trí hiện tại', dataIndex: 'folder_name', width: 160, render: name => name || (scope === 'personal' ? 'File cá nhân' : 'File Sharing') },
                { title: 'Dung lượng', dataIndex: 'file_size', width: 110, render: size => size == null ? '—' : formatStorageSize(size) },
            ]}
            rowSelection={{
                selectedRowKeys: selected, preserveSelectedRowKeys: true,
                getCheckboxProps: file => ({ disabled: moving || (selected.length >= 100 && !selected.includes(file.id)) }),
                onChange: keys => {
                    if (keys.length > 100) { message.warning('Chọn tối đa 100 file mỗi lần.'); return; }
                    setSelected(keys);
                },
            }}
            pagination={{ current: page, pageSize: 10, total, hideOnSinglePage: true, showSizeChanger: false, onChange: next => !moving && setPage(next) }}
            locale={{ emptyText: search ? 'Không tìm thấy file phù hợp.' : 'Không có file nào khác mà bạn có quyền chuyển vào thư mục này.' }}
        />
        <p>Đã chọn {selected.length} file · Tối đa 100 file mỗi lần.</p>
    </Modal>;
}
