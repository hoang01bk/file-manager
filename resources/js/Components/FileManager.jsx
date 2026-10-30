import React, { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Button, Checkbox, Drawer, Dropdown, Image, Input, Modal, Pagination, Select, Table, Tooltip, Upload, message } from 'antd';
import {
    ClockCircleOutlined, DeleteOutlined, DownloadOutlined, EyeOutlined, FileExcelOutlined,
    FileImageOutlined, FileOutlined, FilePdfOutlined, FileTextOutlined, FileWordOutlined,
    FolderOpenOutlined, GlobalOutlined, InfoCircleOutlined, MoreOutlined, PlusOutlined,
    SendOutlined, SettingOutlined, TeamOutlined, UploadOutlined,
} from '@ant-design/icons';
import FileWorkspace from '@/Layouts/FileWorkspace';
import OfficeFileActions, { isOfficeFile, officeUrl } from './OfficeFileActions';
import FileEditorSelect, { editorSelectionPayload } from './FileEditorSelect';
import FilePermissionsModal from './FilePermissionsModal';
import PdfMergeModal from './PdfMergeModal';
import StorageQuota, { formatStorageSize } from './StorageQuota';

const ttlOptions = [
    { value: 1, label: '1 phút' }, { value: 5, label: '5 phút' }, { value: 10, label: '10 phút' },
    { value: 60, label: '1 giờ' }, { value: 1440, label: '24 giờ' }, { value: 10080, label: '7 ngày' },
];
const dateLabel = value => new Date(value).toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
const timeLabel = value => new Date(value).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });

export default function FileManager({ personal = false, files, posts, stats = {}, storageQuota, filters = {} }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    const scope = personal ? 'personal' : 'shared';
    const rows = personal ? files : files.data;
    const notes = personal ? posts : posts.data;
    const [uploadOpen, setUploadOpen] = useState(false);
    const [fileList, setFileList] = useState([]);
    const [uploading, setUploading] = useState(false);
    const [uploadProgress, setUploadProgress] = useState(null);
    const [editorIds, setEditorIds] = useState([]);
    const [viewOnly, setViewOnly] = useState(false);
    const [ttl, setTtl] = useState(1440);
    const [postTtl, setPostTtl] = useState(1440);
    const [postContent, setPostContent] = useState('');
    const [posting, setPosting] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [previewFile, setPreviewFile] = useState(null);
    const [permissionsFile, setPermissionsFile] = useState(null);
    const [mergeFile, setMergeFile] = useState(null);

    const upload = () => {
        const file = fileList[0]?.originFileObj || fileList[0];
        if (!file) return;
        setUploading(true);
        setUploadProgress(null);
        router.post(personal ? '/my-files/upload' : '/upload', {
            file,
            ...(personal ? {} : { ttl_minutes: ttl, view_only: viewOnly }),
            ...(user ? editorSelectionPayload(editorIds) : {}),
        }, {
            forceFormData: true, preserveScroll: true,
            onProgress: progress => setUploadProgress(progress.percentage),
            onSuccess: () => {
                message.success('Tải file lên thành công.');
                setFileList([]); setEditorIds([]); setViewOnly(false); setUploadOpen(false);
            },
            onError: errors => { if (!errors.session) message.error(Object.values(errors)[0] || 'Không tải được file. Vui lòng thử lại.'); },
            onFinish: () => { setUploading(false); setUploadProgress(null); },
        });
    };

    const post = () => {
        if (!postContent.trim()) { message.warning('Vui lòng nhập nội dung.'); return; }
        setPosting(true);
        router.post(personal ? '/user-posts' : '/posts', {
            content: postContent, ...(personal ? {} : { ttl_minutes: postTtl }),
        }, {
            preserveScroll: true,
            onSuccess: () => { setPostContent(''); message.success(personal ? 'Đã lưu ghi chú.' : 'Đã chia sẻ thông báo.'); },
            onError: errors => { if (!errors.session) message.error(Object.values(errors)[0] || 'Không lưu được nội dung.'); },
            onFinish: () => setPosting(false),
        });
    };

    const changePage = (pageName, page) => {
        const query = new URLSearchParams(window.location.search);
        query.set(pageName, String(page));
        router.get(window.location.pathname, Object.fromEntries(query), {
            only: pageName === 'files_page' ? ['files', 'stats', 'storageQuota'] : ['posts'],
            preserveState: true, preserveScroll: true, replace: true,
        });
    };

    const search = value => {
        const query = new URLSearchParams(window.location.search);
        query.delete('files_page');
        if (value.trim()) query.set('q', value.trim()); else query.delete('q');
        router.get(personal ? '/my-files' : '/', Object.fromEntries(query), {
            only: ['files', 'filters'], preserveState: true, preserveScroll: true, replace: true,
        });
    };

    const refresh = () => {
        setRefreshing(true);
        router.reload({ only: ['files', 'posts', 'userPosts', 'stats', 'storageQuota'], onFinish: () => setRefreshing(false) });
    };

    const removeFile = file => {
        if (window.confirm(`Xóa file “${file.file_name}”?`)) {
            router.delete(personal ? `/my-files/${file.id}` : `/files/${file.id}`, { preserveScroll: true });
        }
    };

    const columns = [
        {
            title: 'Tên file', dataIndex: 'file_name', key: 'name', width: 360,
            render: (name, file) => <FileName file={file} personal={personal} userId={user?.id} />,
        },
        {
            title: 'Ngày tải lên', dataIndex: 'created_at', key: 'date', width: 135,
            render: value => <div className="workspace-date"><span>{dateLabel(value)}</span><small>{timeLabel(value)}</small></div>,
        },
        ...(!personal ? [{
            title: 'Thời gian còn lại', key: 'expiry', width: 145,
            render: (_, file) => <Countdown expiry={file.expired_at} />,
        }] : []),
        {
            title: <span className="sr-only">Thao tác</span>, key: 'actions', width: 285,
            render: (_, file) => {
                const owner = user?.id != null && Number(user.id) === Number(file.user_id);
                const available = !file.expired_at || new Date(file.expired_at) > new Date();
                const canEdit = personal || owner || file.is_editor || file.all_users_can_edit || (file.user_id == null && !file.view_only);
                const canDownload = personal || !file.view_only || owner || file.is_editor || file.all_users_can_edit;
                const menu = [
                    ...(canEdit && available && /\.pdf$/i.test(file.file_name) ? [{ key: 'merge-pdf', icon: <FilePdfOutlined />, label: 'Ghép PDF', onClick: () => setMergeFile(file) }] : []),
                    ...(owner && available ? [{ key: 'permissions', icon: <SettingOutlined />, label: 'Quyền truy cập', onClick: () => setPermissionsFile(file) }] : []),
                    ...((personal ? owner : file.user_id == null || owner) ? [{ key: 'delete', icon: <DeleteOutlined />, label: 'Xóa file', danger: true, onClick: () => removeFile(file) }] : []),
                ];
                return (
                    <div className="workspace-file-actions">
                        <Button type="text" icon={<EyeOutlined />} onClick={() => isOfficeFile(file.file_name) ? window.open(officeUrl(scope, file.id), '_blank', 'noopener,noreferrer') : setPreviewFile(file)}>Xem</Button>
                        <OfficeFileActions file={file} scope={scope} canEdit={canEdit && available} />
                        {canDownload && <Tooltip title="Tải về"><Button type="text" icon={<DownloadOutlined />} aria-label={`Tải ${file.file_name}`} onClick={() => { window.location.href = personal ? `/my-files/${file.id}/download` : `/files/${file.id}/download`; }} /></Tooltip>}
                        {menu.length > 0 && <Dropdown menu={{ items: menu }} trigger={['click']}><Button type="text" icon={<MoreOutlined />} aria-label={`Thao tác với ${file.file_name}`} /></Dropdown>}
                    </div>
                );
            },
        },
    ];

    return (
        <FileWorkspace personal={personal} storageQuota={storageQuota} query={filters.q} onSearch={search} onUpload={() => setUploadOpen(true)} onRefresh={refresh} refreshing={refreshing}>
            <Head title={personal ? 'File cá nhân' : 'Không gian chung'} />
            <section className="workspace-canvas" aria-label="Danh sách file">
                <div className="workspace-page-heading">
                    <div>
                        <h1>{personal ? 'File cá nhân' : 'File Share'}</h1>
                        {personal && <p>Tài liệu của bạn và những file được chia sẻ với bạn.</p>}
                    </div>
                    <div className="workspace-heading-icon">{personal ? <FolderOpenOutlined /> : <GlobalOutlined />}</div>
                </div>
                <div className="workspace-summary">
                    <span className="workspace-summary-item"><FileOutlined /><strong>{personal ? files.length : files.total}</strong> {filters.q ? 'kết quả' : 'file'}</span>
                    {!personal && stats.expiring_files > 0 && <span className="workspace-summary-item warning"><ClockCircleOutlined />{stats.expiring_files} file hết hạn trong 24h</span>}
                    {personal && <span className="workspace-summary-item"><TeamOutlined />{files.filter(file => Number(file.user_id) !== Number(user?.id)).length} file được chia sẻ</span>}
                    {filters.q && <Button size="small" type="text" onClick={() => search('')}>Bỏ tìm kiếm</Button>}
                </div>
                <div className="workspace-table">
                    <Table
                        key={`${scope}-${filters.q || ''}`}
                        columns={columns} dataSource={rows} rowKey="id" scroll={{ x: personal ? 790 : 925 }}
                        locale={{ emptyText: <EmptyFiles searching={Boolean(filters.q)} onUpload={() => setUploadOpen(true)} /> }}
                        pagination={personal ? { pageSize: 8, hideOnSinglePage: true, showSizeChanger: false } : {
                            current: files.current_page, pageSize: files.per_page, total: files.total,
                            showSizeChanger: false, hideOnSinglePage: true, onChange: page => changePage('files_page', page),
                        }}
                    />
                </div>
            </section>

            <section className="workspace-canvas workspace-feed" aria-label={personal ? 'Ghi chú cá nhân' : 'Thông báo chung'}>
                <div className="workspace-section-heading"><h2><FileTextOutlined />{personal ? 'Ghi chú cá nhân' : 'Trao đổi thông tin'}</h2><span>{personal ? notes.length : posts.total} {personal ? 'ghi chú' : 'thông báo'}</span></div>
                <div className="workspace-notes-layout">
                <div className="workspace-composer workspace-compact-composer">
                    <label className="workspace-field-label" htmlFor={`${scope}-note-content`}>{personal ? 'Ghi chú mới' : 'Thông báo mới'}</label>
                    <Input.TextArea id={`${scope}-note-content`} value={postContent} onChange={event => setPostContent(event.target.value)} autoSize={{ minRows: 3, maxRows: 6 }} maxLength={personal ? 2000 : undefined} placeholder={personal ? 'Viết điều bạn cần nhớ…' : 'Chia sẻ thông tin với mọi người…'} aria-label={personal ? 'Nội dung ghi chú' : 'Nội dung thông báo'} disabled={posting} />
                    {!personal && <div className="workspace-note-ttl"><span>Tự xóa sau</span><Select aria-label="Thời gian xóa thông báo" size="small" variant="borderless" value={postTtl} onChange={setPostTtl} options={ttlOptions} disabled={posting} style={{ width: 95 }} /></div>}
                    <div className="workspace-composer-footer">
                        <Button type="primary" size="small" icon={<SendOutlined />} onClick={post} loading={posting} disabled={!postContent.trim()}>{personal ? 'Lưu ghi chú' : 'Chia sẻ'}</Button>
                    </div>
                    {personal && <p className="workspace-personal-note-privacy">Chỉ bạn nhìn thấy những ghi chú này.</p>}
                </div>
                {notes.length ? <div className="workspace-note-list workspace-compact-note-list">{notes.map(note => (
                    <article className="workspace-note workspace-compact-note" key={note.id}>
                        <span className="workspace-note-icon" aria-hidden="true"><FileTextOutlined /></span>
                        <div className="workspace-note-body">
                        <p>{note.content}</p>
                        <div className="workspace-note-footer"><time dateTime={note.created_at}>{timeLabel(note.created_at)} · {dateLabel(note.created_at)}</time>
                        </div>
                        {!personal && note.expires_at && <span className="workspace-note-expiry">Hết hạn lúc {timeLabel(note.expires_at)} · {dateLabel(note.expires_at)}</span>}
                        </div>
                        {personal && <Tooltip title="Xóa ghi chú"><Button className="workspace-personal-note-delete" type="text" danger size="small" icon={<DeleteOutlined />} aria-label="Xóa ghi chú" onClick={() => { if (window.confirm('Xóa ghi chú này?')) router.delete(`/user-posts/${note.id}`, { preserveScroll: true }); }} /></Tooltip>}
                    </article>
                ))}</div> : <div className="workspace-empty"><strong>{personal ? 'Chưa có ghi chú nào' : 'Chưa có thông báo nào'}</strong><p>{personal ? 'Bắt đầu bằng một ý tưởng hoặc việc cần làm.' : 'Viết thông báo đầu tiên để chia sẻ với mọi người.'}</p></div>}
                </div>
                {!personal && <div className="workspace-feed-pagination"><Pagination current={posts.current_page} pageSize={posts.per_page} total={posts.total} size="small" hideOnSinglePage showSizeChanger={false} onChange={page => changePage('posts_page', page)} /></div>}
            </section>

            <Drawer title="Tải file lên" open={uploadOpen} onClose={() => !uploading && setUploadOpen(false)} width={440} className="workspace-upload-drawer" styles={{ wrapper: { maxWidth: '100vw' } }} maskClosable={!uploading} closable={!uploading}>
                <p className="workspace-upload-description">{personal ? 'Lưu tài liệu trong không gian cá nhân và chọn người có thể chỉnh sửa.' : 'Chia sẻ file lên không gian chung và đặt thời gian tự xóa.'}</p>
                <Upload.Dragger className="workspace-dropzone" fileList={fileList} maxCount={1} disabled={uploading} onChange={({ fileList: selected }) => setFileList(selected.slice(-1))} beforeUpload={file => {
                    if (file.size > 100 * 1024 * 1024) { message.error('File không được vượt quá 100 MB.'); return Upload.LIST_IGNORE; }
                    return false;
                }}>
                    <div className="upload-illustration"><UploadOutlined /></div><p className="upload-title">Kéo thả file vào đây</p><p className="upload-hint">hoặc nhấn để chọn file · Tối đa 100 MB</p>
                </Upload.Dragger>
                <div className="workspace-upload-settings">
                    {!personal && <><label className="workspace-field-label" htmlFor="upload-ttl">Tự động xóa file sau</label><Select id="upload-ttl" value={ttl} onChange={setTtl} options={ttlOptions} disabled={uploading} style={{ width: '100%' }} /></>}
                    {user && <FileEditorSelect value={editorIds} onChange={setEditorIds} disabled={uploading} />}
                    {!personal && user && <Checkbox checked={viewOnly} onChange={event => setViewOnly(event.target.checked)} disabled={uploading}>Chặn tải file đối với người không được cấp quyền</Checkbox>}
                </div>
                <Button type="primary" block icon={<UploadOutlined />} loading={uploading} disabled={!fileList.length} onClick={upload}>{uploading ? `Đang tải lên${uploadProgress == null ? '…' : ` ${uploadProgress}%`}` : 'Tải file lên'}</Button>
                <div className="workspace-upload-note"><InfoCircleOutlined /><span>{personal ? 'File cá nhân được lưu cho đến khi bạn chủ động xóa.' : 'File sẽ bị xóa vĩnh viễn khi hết thời gian lưu đã chọn.'}</span></div>
                <div className="workspace-upload-quota"><StorageQuota quota={storageQuota} /></div>
            </Drawer>
            <FilePermissionsModal file={permissionsFile} scope={scope} onClose={() => setPermissionsFile(null)} />
            <PdfMergeModal file={mergeFile} scope={scope} onClose={() => setMergeFile(null)} />
            <Preview file={previewFile} personal={personal} onClose={() => setPreviewFile(null)} />
        </FileWorkspace>
    );
}

function FileName({ file, personal, userId }) {
    const ext = file.file_name.split('.').pop().toLowerCase();
    const [kind, icon] = /^(docx?|odt)$/.test(ext) ? ['doc', <FileWordOutlined />]
        : /^(xlsx?|csv|ods)$/.test(ext) ? ['sheet', <FileExcelOutlined />]
        : ext === 'pdf' ? ['pdf', <FilePdfOutlined />]
        : /^(png|jpe?g|gif|webp|svg|avif|bmp|ico)$/.test(ext) ? ['image', <FileImageOutlined />] : ['', <FileOutlined />];
    return <div className="workspace-file-name"><span className={`workspace-file-type ${kind}`}>{icon}</span><div className="workspace-file-text"><strong title={file.file_name}>{file.file_name}</strong><small>{file.file_size != null ? formatStorageSize(Number(file.file_size)) : ext.toUpperCase()}{personal && Number(file.user_id) !== Number(userId) ? ` · Chia sẻ bởi ${file.user?.name || 'người dùng khác'}` : ''}</small></div></div>;
}

function EmptyFiles({ searching, onUpload }) {
    return <div className="workspace-empty"><div className="workspace-empty-icon"><FolderOpenOutlined /></div><strong>{searching ? 'Không tìm thấy file phù hợp' : 'Không gian cho những tài liệu của bạn'}</strong><p>{searching ? 'Thử tìm bằng một tên file khác.' : 'Tải file đầu tiên lên để bắt đầu chia sẻ và làm việc.'}</p>{!searching && <Button icon={<PlusOutlined />} onClick={onUpload}>Tải file đầu tiên</Button>}</div>;
}

function Countdown({ expiry }) {
    const [remaining, setRemaining] = useState(() => new Date(expiry) - Date.now());
    useEffect(() => {
        const tick = () => setRemaining(new Date(expiry) - Date.now());
        tick(); const timer = window.setInterval(tick, 1000);
        return () => window.clearInterval(timer);
    }, [expiry]);
    const hours = Math.floor(remaining / 3600000);
    const label = remaining <= 0 ? 'Đã hết hạn' : `${String(hours).padStart(2, '0')}:${String(Math.floor(remaining % 3600000 / 60000)).padStart(2, '0')}:${String(Math.floor(remaining % 60000 / 1000)).padStart(2, '0')}`;
    return <span className={`workspace-countdown ${remaining <= 0 ? 'expired' : hours < 24 ? 'warning' : ''}`}><ClockCircleOutlined />{label}</span>;
}

function Preview({ file, personal, onClose }) {
    const url = file ? (personal ? `/my-files/${file.id}/preview` : `/files/${file.id}/preview`) : '';
    return <Modal title={file?.file_name} open={Boolean(file)} onCancel={onClose} footer={null} width={960} styles={{ body: { padding: 0 } }} destroyOnClose>
        {file && (/\.(mp4|webm|ogg|mov|m4v)$/i.test(file.file_name)
            ? <video controls controlsList="nodownload" playsInline onContextMenu={event => event.preventDefault()} style={{ width: '100%', height: '70vh', background: '#101418' }} src={url}>Trình duyệt không hỗ trợ video này.</video>
            : /\.(avif|bmp|gif|ico|jpe?g|png|svg|webp)$/i.test(file.file_name)
                ? <div className="workspace-preview-image"><Image src={url} alt={file.file_name} style={{ maxHeight: '70vh', maxWidth: '100%', objectFit: 'contain' }} preview={{ maxScale: 8, scaleStep: .5 }} /></div>
                : <iframe title={file.file_name} src={url} style={{ width: '100%', height: '70vh', border: 'none' }} />)}
    </Modal>;
}
