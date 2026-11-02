import React, { lazy, Suspense, useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Alert, Button, Input, Modal, Pagination, Segmented, Select, Spin, Typography, Upload, message } from 'antd';
import { FilePdfOutlined, UploadOutlined } from '@ant-design/icons';

const PdfPageGrid = lazy(() => import('./PdfPageGrid'));

const ttlOptions = [
    { value: 60, label: '1 giờ' }, { value: 1440, label: '24 giờ' }, { value: 10080, label: '7 ngày' },
];

function selection(expression, total, limit) {
    if (!expression.trim()) return { pages: [], error: 'Chọn ít nhất một trang để tạo PDF mới.' };
    if (!/^\s*\d+\s*(?:-\s*\d+\s*)?(?:,\s*\d+\s*(?:-\s*\d+\s*)?)*$/.test(expression)) return { pages: [], error: 'Nhập trang theo dạng 1,3,5-8.' };
    const pages = new Set();
    for (const part of expression.replace(/\s+/g, '').split(',')) {
        const bounds = part.split('-').map(Number);
        const first = bounds[0], last = bounds[1] ?? first;
        if (![first, last].every(page => Number.isSafeInteger(page) && page >= 1 && page <= total)) return { pages: [], error: `Số trang phải từ 1 đến ${total}.` };
        const step = first <= last ? 1 : -1;
        for (let page = first; ; page += step) {
            pages.add(page);
            if (pages.size > limit) return { pages: [], error: `Chỉ được lấy tối đa ${limit} trang mỗi lần.` };
            if (page === last) break;
        }
    }
    return { pages: [...pages], error: '' };
}

function compact(pages) {
    const ranges = [];
    for (let index = 0; index < pages.length; index++) {
        const start = pages[index];
        let end = start;
        while (index + 1 < pages.length && pages[index + 1] === end + 1) end = pages[++index];
        ranges.push(start === end ? `${start}` : `${start}-${end}`);
    }
    return ranges.join(',');
}

function errorMessage(error) {
    const errors = error.response?.data?.errors;
    if (errors) return Object.values(errors).flat()[0];
    return ({
        403: 'Bạn không có quyền xử lý PDF này hoặc phiên đăng nhập đã thay đổi.',
        404: 'Không tìm thấy PDF gốc.', 410: 'PDF gốc đã hết hạn.',
        413: 'PDF không được vượt quá 100 MB.', 415: 'Chỉ hỗ trợ file PDF.',
        419: 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.',
        503: 'Chức năng xử lý PDF chưa sẵn sàng trên máy chủ.',
    })[error.response?.status] || 'Không xử lý được PDF. Vui lòng thử lại.';
}

export default function PdfExtractModal({ open, initialFile, scope, onClose }) {
    const [mode, setMode] = useState('local');
    const [files, setFiles] = useState([]);
    const [sourceId, setSourceId] = useState(null);
    const [sources, setSources] = useState([]);
    const [search, setSearch] = useState('');
    const [searching, setSearching] = useState(false);
    const [reading, setReading] = useState(false);
    const [pageCount, setPageCount] = useState(0);
    const [pages, setPages] = useState('');
    const [limit, setLimit] = useState(5000);
    const [gridPage, setGridPage] = useState(1);
    const [name, setName] = useState('');
    const [ttl, setTtl] = useState(1440);
    const [saving, setSaving] = useState(false);
    const [progress, setProgress] = useState(null);
    const [error, setError] = useState('');
    const localFile = files[0]?.originFileObj || files[0];

    useEffect(() => {
        if (!open) return;
        setMode(initialFile ? 'stored' : 'local');
        setSourceId(initialFile?.id ?? null);
        setSources(initialFile ? [initialFile] : []);
        setFiles([]); setSearch(''); setName(''); setPages('');
        setPageCount(0); setGridPage(1); setError(''); setProgress(null); setTtl(1440);
    }, [open, initialFile, scope]);

    useEffect(() => {
        if (!open || mode !== 'stored') return;
        const controller = new AbortController();
        setSearching(true);
        const timer = setTimeout(() => {
            axios.get(`/pdf/${scope}/sources`, { params: { q: search }, signal: controller.signal })
                .then(({ data }) => {
                    if (controller.signal.aborted) return;
                    setSources(initialFile && !data.files.some(file => file.id === initialFile.id) ? [initialFile, ...data.files] : data.files);
                })
                .catch(requestError => { if (!controller.signal.aborted) setError(errorMessage(requestError)); })
                .finally(() => { if (!controller.signal.aborted) setSearching(false); });
        }, 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [open, mode, search, scope, initialFile]);

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        setPageCount(0); setPages(''); setGridPage(1); setError('');
        if ((mode === 'local' && !localFile) || (mode === 'stored' && !sourceId)) {
            setReading(false);
            return () => controller.abort();
        }
        setReading(true);
        let request;
        if (mode === 'local') {
            const body = new FormData();
            body.append('file', localFile);
            request = axios.post(`/pdf/${scope}/inspect`, body, { signal: controller.signal });
        } else {
            request = axios.get(`/pdf/${scope}/${sourceId}/info`, { signal: controller.signal });
        }
        request.then(({ data }) => {
            if (controller.signal.aborted) return;
            setPageCount(data.page_count);
            setLimit(data.selection_limit || 5000);
            setName(`${data.name.replace(/\.pdf$/i, '').slice(0, 175)} - đã tách.pdf`);
        }).catch(requestError => {
            if (!controller.signal.aborted) setError(errorMessage(requestError));
        }).finally(() => { if (!controller.signal.aborted) setReading(false); });
        return () => controller.abort();
    }, [open, mode, localFile, sourceId, scope]);

    const selected = useMemo(() => selection(pages, pageCount, limit), [pages, pageCount, limit]);
    const selectedSet = useMemo(() => new Set(selected.pages), [selected.pages]);
    const firstPage = (gridPage - 1) * 60 + 1;
    const visiblePages = Array.from({ length: Math.max(0, Math.min(60, pageCount - firstPage + 1)) }, (_, index) => firstPage + index);
    const togglePage = (page, checked) => {
        const next = checked ? [...selected.pages, page] : selected.pages.filter(item => item !== page);
        if (next.length > limit) { message.warning(`Chỉ được lấy tối đa ${limit} trang mỗi lần.`); return; }
        setPages(compact(next.sort((a, b) => a - b)));
    };

    const extract = async () => {
        if (selected.error || !name.trim()) return;
        setSaving(true); setError(''); setProgress(null);
        const body = new FormData();
        body.append('name', name.trim()); body.append('pages', pages);
        if (mode === 'local') {
            body.append('file', localFile);
            if (scope === 'shared') body.append('ttl_minutes', ttl);
        } else body.append('source_id', sourceId);
        try {
            await axios.post(`/pdf/${scope}/extract`, body, {
                onUploadProgress: event => setProgress(event.total ? Math.round(event.loaded * 100 / event.total) : null),
            });
            message.success('Đã tạo PDF mới từ các trang đã chọn.');
            onClose();
            router.reload({ only: ['files', 'stats', 'storageQuota'], preserveScroll: true });
        } catch (requestError) {
            setError(errorMessage(requestError));
        } finally { setSaving(false); setProgress(null); }
    };

    return (
        <Modal title="Tách PDF" open={open} width={960} style={{ top: 24 }} onCancel={() => !saving && onClose()} onOk={extract}
            okText={saving ? (progress !== null && progress < 100 ? `Đang tải lên ${progress}%` : 'Đang tạo PDF…') : 'Tạo PDF mới'}
            cancelText="Hủy" confirmLoading={saving} okButtonProps={{ disabled: reading || !pageCount || !!selected.error || !name.trim() }}
            cancelButtonProps={{ disabled: saving }} closable={!saving} maskClosable={!saving} keyboard={!saving}>
            <div className="workspace-pdf-extract">
                <Typography.Paragraph type="secondary">Chọn những trang cần giữ để tạo một PDF mới. File gốc được giữ nguyên.</Typography.Paragraph>
                <Segmented block value={mode} onChange={setMode} disabled={saving} options={[
                    { value: 'local', label: 'Chọn từ máy' }, { value: 'stored', label: 'PDF đã upload' },
                ]} />
                {mode === 'local' ? <Upload.Dragger className="workspace-pdf-extract-upload" accept=".pdf,application/pdf" maxCount={1}
                    fileList={files} onChange={({ fileList }) => setFiles(fileList.slice(-1))} disabled={saving}
                    beforeUpload={file => {
                        if (!/\.pdf$/i.test(file.name)) { message.error('Chỉ hỗ trợ file PDF.'); return Upload.LIST_IGNORE; }
                        if (file.size > 100 * 1024 * 1024) { message.error('PDF không được vượt quá 100 MB.'); return Upload.LIST_IGNORE; }
                        return false;
                    }}>
                    <UploadOutlined /><p>Chọn hoặc kéo thả một PDF</p><small>PDF tối đa 100 MB</small>
                </Upload.Dragger> : <div className="workspace-pdf-extract-source">
                    <label className="workspace-field-label" htmlFor="extract-pdf-source">PDF có quyền chỉnh sửa</label>
                    <Select id="extract-pdf-source" showSearch value={sourceId} onChange={setSourceId} onSearch={value => setSearch(value.slice(0, 200))}
                        filterOption={false} loading={searching} disabled={saving} style={{ width: '100%' }}
                        placeholder="Tìm và chọn PDF đã upload" options={sources.map(file => ({ value: file.id, label: file.file_name }))}
                        notFoundContent={searching ? <Spin size="small" /> : 'Không tìm thấy PDF phù hợp'} />
                </div>}
                {reading && <div className="workspace-pdf-extract-loading" role="status"><Spin size="small" /><span>Đang đọc các trang PDF…</span></div>}
                {open && pageCount > 0 && <>
                    <label className="workspace-field-label" htmlFor="extract-pdf-pages">Hoặc nhập trang cần lấy</label>
                    <Input id="extract-pdf-pages" value={pages} onChange={event => setPages(event.target.value)} maxLength={50000} placeholder="Ví dụ: 1,3,5-8"
                        disabled={saving} status={pages && selected.error ? 'error' : undefined} aria-describedby="extract-pdf-selection-help" />
                    <p id="extract-pdf-selection-help" className={`workspace-pdf-selection-help${selected.error && pages ? ' invalid' : ''}`}>
                        {selected.error || `Đã chọn ${selected.pages.length} trang. Thứ tự trang trong PDF mới theo thứ tự nhập.`}
                    </p>
                    <label className="workspace-field-label" htmlFor="extract-pdf-name">Tên PDF mới</label>
                    <Input id="extract-pdf-name" value={name} onChange={event => setName(event.target.value)} maxLength={200} disabled={saving} />
                    {mode === 'local' && scope === 'shared' && <>
                        <label className="workspace-field-label" htmlFor="extract-pdf-ttl">Tự động xóa PDF mới sau</label>
                        <Select id="extract-pdf-ttl" value={ttl} onChange={setTtl} options={ttlOptions} disabled={saving} style={{ width: '100%' }} />
                    </>}
                    <Typography.Paragraph type="secondary" className="workspace-pdf-extract-note">
                        PDF mới được lưu vào {scope === 'personal' ? 'File cá nhân' : 'File Share'} và tính vào dung lượng của bạn. {mode === 'stored' ? 'Quyền truy cập và hạn dùng giữ theo file gốc.' : 'Chỉ bản PDF mới được lưu lên hệ thống.'} Tối đa {limit} trang và 100 MB mỗi bản.
                    </Typography.Paragraph>
                    <div className="workspace-pdf-page-heading"><strong><FilePdfOutlined /> {pageCount} trang</strong>
                        <div><Button size="small" type="text" disabled={saving || pageCount > limit} onClick={() => setPages(pageCount === 1 ? '1' : `1-${pageCount}`)}>Chọn tất cả</Button>
                            <Button size="small" type="text" disabled={saving || !pages} onClick={() => setPages('')}>Bỏ chọn</Button></div>
                    </div>
                    <Suspense fallback={<div className="workspace-pdf-extract-loading"><Spin size="small" /><span>Đang tải ảnh xem trước…</span></div>}>
                        <PdfPageGrid key={`${scope}-${mode}-${sourceId}-${localFile?.uid}`}
                            source={mode === 'local' ? localFile : `/pdf/${scope}/${sourceId}/preview`}
                            pages={visiblePages} selected={selectedSet} disabled={saving} onToggle={togglePage} />
                    </Suspense>
                    {pageCount > 60 && <Pagination size="small" current={gridPage} pageSize={60} total={pageCount} showSizeChanger={false} onChange={setGridPage}
                        showTotal={(_, range) => `Trang ${range[0]}–${range[1]}`} />}
                </>}
                {error && <Alert type="error" showIcon title={error} />}
            </div>
        </Modal>
    );
}
