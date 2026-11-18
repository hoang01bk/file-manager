import React, { lazy, Suspense, useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Breadcrumb, Button, Checkbox, Drawer, Dropdown, Image, Input, Modal, Pagination, Segmented, Select, Table, Tooltip, Upload, message } from 'antd';
import axios from 'axios';
import {
    ClockCircleOutlined, DeleteOutlined, DownloadOutlined, EditOutlined, EyeOutlined, FileExcelOutlined, LoginOutlined,
    FileImageOutlined, FileOutlined, FilePdfOutlined, FilePptOutlined, FileTextOutlined, FileWordOutlined,
    AppstoreOutlined, FolderOpenOutlined, InfoCircleOutlined, MoreOutlined, PlusOutlined, UnorderedListOutlined,
    SendOutlined, SettingOutlined, TeamOutlined, UploadOutlined, ScissorOutlined,
} from '@ant-design/icons';
import FileWorkspace from '@/Layouts/FileWorkspace';
import OfficeFileActions, { isOfficeFile, officeLoginUrl, officeUrl } from './OfficeFileActions';
import FileThumbnail from './FileThumbnail';
import FileEditorSelect, { editorSelectionPayload } from './FileEditorSelect';
import StorageQuota, { formatStorageSize } from './StorageQuota';

const FilePermissionsModal = lazy(() => import('./FilePermissionsModal'));
const PdfMergeModal = lazy(() => import('./PdfMergeModal'));
const PdfExtractModal = lazy(() => import('./PdfExtractModal'));
const ExistingFilesModal = lazy(() => import('./ExistingFilesModal'));
const CreateOfficeFileModal = lazy(() => import('./CreateOfficeFileModal'));

const ttlOptions = [
    { value: 1, label: '1 phút' }, { value: 5, label: '5 phút' }, { value: 10, label: '10 phút' },
    { value: 60, label: '1 giờ' }, { value: 1440, label: '24 giờ' }, { value: 10080, label: '7 ngày' },
];
const dateLabel = value => new Date(value).toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
const timeLabel = value => new Date(value).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });

export default function FileManager({ personal = false, files, posts, stats = {}, storageQuota, filters = {}, folders = [], currentFolder = null, folderBreadcrumbs = [] }) {
    const { props: { auth }, url } = usePage();
    const notifications = !personal && new URL(url, 'http://localhost').searchParams.get('tab') === 'notifications';
    const personalNotes = personal && new URL(url, 'http://localhost').searchParams.get('tab') === 'notes';
    const user = auth?.user;
    const scope = personal ? 'personal' : 'shared';
    const rows = personal ? files : files.data;
    const notes = personal ? posts : posts.data;
    const [uploadOpen, setUploadOpen] = useState(false);
    const [uploadMode, setUploadMode] = useState('file');
    const [folderOpen, setFolderOpen] = useState(false);
    const [folderName, setFolderName] = useState('');
    const [folderError, setFolderError] = useState('');
    const [creatingFolder, setCreatingFolder] = useState(false);
    const [existingFilesOpen, setExistingFilesOpen] = useState(false);
    const [newOfficeType, setNewOfficeType] = useState(null);
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
    const [extractOpen, setExtractOpen] = useState(false);
    const [extractFile, setExtractFile] = useState(null);
    const [fileLayout, setFileLayout] = useState(() => {
        try {
            return window.sessionStorage.getItem('file-manager-layout') === 'grid' ? 'grid' : 'list';
        } catch {
            return 'list';
        }
    });
    const [personalPage, setPersonalPage] = useState(1);
    const [personalPageSize, setPersonalPageSize] = useState(20);
    const pageSize = personal ? personalPageSize : files.per_page;
    const currentPage = personal ? Math.min(personalPage, Math.max(1, Math.ceil(rows.length / pageSize))) : files.current_page;
    const gridRows = personal ? rows.slice((currentPage - 1) * pageSize, currentPage * pageSize) : rows;
    const filePagination = {
        current: currentPage, pageSize, total: personal ? rows.length : files.total,
        hideOnSinglePage: true, showSizeChanger: false,
        onChange: page => personal ? setPersonalPage(page) : changePage('files_page', page),
    };

    const changeFileLayout = layout => {
        setFileLayout(layout);
        try {
            window.sessionStorage.setItem('file-manager-layout', layout);
        } catch {
            // The layout can still change when browser storage is unavailable.
        }
    };

    const changePageSize = size => {
        if (personal) {
            setPersonalPageSize(size);
            setPersonalPage(1);
            return;
        }
        const query = new URLSearchParams(window.location.search);
        query.set('per_page', String(size));
        query.delete('files_page');
        router.get(window.location.pathname, Object.fromEntries(query), {
            only: ['files', 'filters'], preserveState: true, preserveScroll: true, replace: true,
        });
    };

    const openUpload = (mode = 'file') => {
        setUploadMode(mode);
        setFileList([]);
        setUploadProgress(null);
        setUploadOpen(true);
    };

    const createOfficeFile = type => {
        if (!user) { router.visit('/login'); return; }
        setNewOfficeType(type);
    };

    const openFolder = id => {
        setPersonalPage(1);
        router.get(personal ? '/my-files' : '/', {
            ...(id ? { folder: id } : {}),
            ...(!personal ? { per_page: pageSize } : {}),
        }, { preserveState: true });
    };

    const createFolder = () => {
        if (!folderName.trim()) { setFolderError('Vui lòng nhập tên thư mục.'); return; }
        setCreatingFolder(true);
        setFolderError('');
        router.post(`/folders/${scope}`, { name: folderName.trim(), parent_id: currentFolder?.id ?? null }, {
            preserveScroll: true,
            onSuccess: () => {
                setFolderOpen(false); setFolderName(''); message.success('Đã tạo thư mục.');
                if (notifications || personalNotes) openFolder(currentFolder?.id);
            },
            onError: errors => setFolderError(Object.values(errors)[0] || 'Không tạo được thư mục.'),
            onFinish: () => setCreatingFolder(false),
        });
    };

    const uploadDirectory = async () => {
        const pending = fileList.filter(item => item.status !== 'done');
        if (!pending.length) return;
        setUploading(true);
        let completed = fileList.length - pending.length;
        try {
            for (const item of pending) {
                const file = item.originFileObj || item;
                const data = new FormData();
                data.append('file', file);
                data.append('relative_path', file.webkitRelativePath);
                if (currentFolder) data.append('folder_id', currentFolder.id);
                if (!personal) {
                    data.append('ttl_minutes', ttl);
                    data.append('view_only', viewOnly ? '1' : '0');
                }
                if (user) {
                    const editors = editorSelectionPayload(editorIds);
                    data.append('all_users_can_edit', editors.all_users_can_edit ? '1' : '0');
                    editors.editor_ids.forEach(id => data.append('editor_ids[]', id));
                }
                await axios.post(personal ? '/my-files/upload' : '/upload', data, {
                    headers: { Accept: 'application/json' },
                    onUploadProgress: event => {
                        const fraction = event.total ? event.loaded / event.total : 0;
                        setUploadProgress(Math.round((completed + fraction) / fileList.length * 100));
                    },
                });
                completed++;
                setFileList(previous => previous.map(entry => entry.uid === item.uid ? { ...entry, status: 'done' } : entry));
            }
            message.success(`Đã tải ${completed} file và giữ nguyên cấu trúc thư mục.`);
            setFileList([]); setEditorIds([]); setViewOnly(false); setUploadOpen(false);
        } catch (error) {
            const detail = error.response?.status === 419 ? 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.'
                : Object.values(error.response?.data?.errors || {}).flat()[0] || 'Không tải được file tiếp theo.';
            message.error(`Đã tải ${completed}/${fileList.length} file. ${detail} Bạn có thể thử lại các file còn lại.`);
        } finally {
            setUploading(false);
            setUploadProgress(null);
            router.reload({ only: ['files', 'folders', 'stats', 'storageQuota'] });
        }
    };

    const upload = () => {
        if (uploadMode === 'folder') { uploadDirectory(); return; }
        const file = fileList[0]?.originFileObj || fileList[0];
        if (!file) return;
        setUploading(true);
        setUploadProgress(null);
        router.post(personal ? '/my-files/upload' : '/upload', {
            file,
            folder_id: currentFolder?.id ?? null,
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
            only: ['files', 'folders', 'filters'], preserveState: true, preserveScroll: true, replace: true,
        });
    };

    const refresh = () => {
        setRefreshing(true);
        router.reload({ only: ['files', 'folders', 'posts', 'userPosts', 'stats', 'storageQuota'], onFinish: () => setRefreshing(false) });
    };

    const removeFile = file => {
        if (window.confirm(`Xóa file “${file.file_name}”?`)) {
            router.delete(personal ? `/my-files/${file.id}` : `/files/${file.id}`, { preserveScroll: true });
        }
    };

    const openFile = file => isOfficeFile(file.file_name)
        ? window.open(officeUrl(scope, file.id), '_blank', 'noopener,noreferrer') : setPreviewFile(file);

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
            title: <span className="sr-only">Thao tác</span>, key: 'actions', width: !personal && !user ? 355 : 285,
            render: (_, file, compact = false) => {
                const owner = user?.id != null && Number(user.id) === Number(file.user_id);
                const available = !file.expired_at || new Date(file.expired_at) > new Date();
                const canEdit = Boolean(user) && (personal || owner || file.is_editor || file.all_users_can_edit || (file.user_id == null && !file.view_only));
                const canDownload = personal || !file.view_only || canEdit;
                const menu = [
                    ...(canEdit && available && /\.pdf$/i.test(file.file_name) ? [{ key: 'merge-pdf', icon: <FilePdfOutlined />, label: 'Ghép PDF', onClick: () => setMergeFile(file) }] : []),
                    ...(canEdit && available && /\.pdf$/i.test(file.file_name) ? [{ key: 'extract-pdf', icon: <ScissorOutlined />, label: 'Tách PDF', onClick: () => { setExtractFile(file); setExtractOpen(true); } }] : []),
                    ...(owner && available ? [{ key: 'permissions', icon: <SettingOutlined />, label: 'Quyền truy cập', onClick: () => setPermissionsFile(file) }] : []),
                    ...((personal ? owner : file.user_id == null || owner) ? [{ key: 'delete', icon: <DeleteOutlined />, label: 'Xóa file', danger: true, onClick: () => removeFile(file) }] : []),
                ];
                if (compact === true) {
                    const items = [
                        { key: 'view', icon: <EyeOutlined />, label: 'Xem', onClick: () => openFile(file) },
                        ...(isOfficeFile(file.file_name) && canEdit && available ? [{ key: 'edit', icon: <EditOutlined />, label: <a href={officeUrl(scope, file.id, true)} target="_blank" rel="noopener noreferrer">Chỉnh sửa</a> }] : []),
                        ...(isOfficeFile(file.file_name) && !user && !personal && available && (file.all_users_can_edit || (file.user_id == null && !file.view_only)) ? [{ key: 'login', icon: <LoginOutlined />, label: <a href={officeLoginUrl(file.id)}>Đăng nhập để chỉnh sửa</a> }] : []),
                        ...(canDownload ? [{ key: 'download', icon: <DownloadOutlined />, label: <a href={personal ? `/my-files/${file.id}/download` : `/files/${file.id}/download`}>Tải về</a> }] : []),
                        ...menu,
                    ];
                    return <Dropdown menu={{ items }} trigger={['click']}><Button type="text" icon={<MoreOutlined />} aria-label={`Thao tác với ${file.file_name}`} /></Dropdown>;
                }
                return (
                    <div className="workspace-file-actions">
                        <Button type="text" icon={<EyeOutlined />} onClick={() => openFile(file)}>Xem</Button>
                        <OfficeFileActions file={file} scope={scope} canEdit={canEdit && available} guest={!user && !personal && available} />
                        {canDownload && <Tooltip title="Tải về"><Button type="text" icon={<DownloadOutlined />} aria-label={`Tải ${file.file_name}`} onClick={() => { window.location.href = personal ? `/my-files/${file.id}/download` : `/files/${file.id}/download`; }} /></Tooltip>}
                        {menu.length > 0 && <Dropdown menu={{ items: menu }} trigger={['click']}><Button type="text" icon={<MoreOutlined />} aria-label={`Thao tác với ${file.file_name}`} /></Dropdown>}
                    </div>
                );
            },
        },
    ];

    return (
        <FileWorkspace personal={personal} notifications={notifications} personalNotes={personalNotes} storageQuota={storageQuota} query={filters.q} onSearch={search} onUpload={() => openUpload()} onUploadFolder={() => openUpload('folder')} onCreateFolder={() => { setFolderError(''); setFolderName(''); setFolderOpen(true); }} onAddExistingFiles={currentFolder && !notifications && !personalNotes ? () => setExistingFilesOpen(true) : undefined} onCreateOffice={createOfficeFile} onRefresh={refresh} refreshing={refreshing}>
            <Head title={personalNotes ? 'Ghi chú cá nhân' : personal ? 'File cá nhân' : notifications ? 'Thông báo' : 'Không gian chung'} />
            {!notifications && !personalNotes && <section className="workspace-canvas workspace-files" aria-label="Danh sách file">
                <div className="workspace-page-heading">
                    <div>
                        <h1>{currentFolder?.name || (personal ? 'File cá nhân' : 'File Share')}</h1>
                        {personal && <p>Tài liệu của bạn và những file được chia sẻ với bạn.</p>}
                    </div>
                    <div className="workspace-heading-actions">
                        {currentFolder && <Button icon={<PlusOutlined />} onClick={() => setExistingFilesOpen(true)}>Thêm file đã upload</Button>}
                        <Button icon={<ScissorOutlined />} onClick={() => { setExtractFile(null); setExtractOpen(true); }}>Tách PDF</Button>
                        <Segmented
                            className="workspace-layout-switch"
                            aria-label="Bố cục danh sách file"
                            value={fileLayout}
                            onChange={changeFileLayout}
                            options={[
                                { value: 'list', icon: <UnorderedListOutlined aria-label="Dạng danh sách" />, title: 'Dạng danh sách' },
                                { value: 'grid', icon: <AppstoreOutlined aria-label="Dạng lưới" />, title: 'Dạng lưới' },
                            ]}
                        />
                    </div>
                </div>
                {currentFolder && <Breadcrumb className="workspace-folder-breadcrumbs" items={[
                    { title: <button type="button" onClick={() => openFolder(null)}>{personal ? 'File cá nhân' : 'File Sharing'}</button> },
                    ...folderBreadcrumbs.map((folder, index) => ({
                        title: index === folderBreadcrumbs.length - 1 ? folder.name : <button type="button" onClick={() => openFolder(folder.id)}>{folder.name}</button>,
                    })),
                ]} />}
                <div className="workspace-summary">
                    <span className="workspace-summary-item"><FileOutlined /><strong>{personal ? files.length : files.total}</strong> {filters.q ? 'kết quả' : 'file'}</span>
                    {folders.length > 0 && <span className="workspace-summary-item"><FolderOpenOutlined /><strong>{folders.length}</strong> thư mục</span>}
                    {!personal && stats.expiring_files > 0 && <span className="workspace-summary-item warning"><ClockCircleOutlined />{stats.expiring_files} file hết hạn trong 24h</span>}
                    {personal && <span className="workspace-summary-item"><TeamOutlined />{files.filter(file => Number(file.user_id) !== Number(user?.id)).length} file được chia sẻ</span>}
                    {filters.q && <Button size="small" type="text" onClick={() => search('')}>Bỏ tìm kiếm</Button>}
                    <div className="workspace-page-controls">
                        <div className="workspace-page-size">
                            <span>Hiển thị</span>
                            <Select
                                aria-label="Số file mỗi trang" size="small" value={pageSize} onChange={changePageSize}
                                options={[20, 50, 100].map(value => ({ value, label: `${value} file / trang` }))}
                                popupMatchSelectWidth={false}
                            />
                        </div>
                        <nav aria-label="Chọn trang danh sách file">
                            <Pagination {...filePagination} responsive showLessItems />
                        </nav>
                    </div>
                </div>
                {folders.length > 0 && <div className="workspace-folder-list" role="list" aria-label="Thư mục">
                    {folders.map(folder => <div className="workspace-folder-item" role="listitem" key={folder.id}>
                        <button type="button" className="workspace-folder-open" onClick={() => openFolder(folder.id)} title={folder.name}>
                            <FolderOpenOutlined /><span>{folder.name}</span>
                        </button>
                        {((!personal && folder.user_id == null) || (user && Number(folder.user_id) === Number(user.id))) && <Dropdown trigger={['click']} menu={{ items: [
                            { key: 'delete', label: 'Xóa thư mục trống', icon: <DeleteOutlined />, danger: true, onClick: () => {
                                if (window.confirm(`Xóa thư mục “${folder.name}”?`)) router.delete(`/folders/${scope}/${folder.id}`, {
                                    preserveScroll: true, onError: errors => message.error(Object.values(errors)[0] || 'Không xóa được thư mục.'),
                                });
                            } },
                        ] }}><Button type="text" icon={<MoreOutlined />} aria-label={`Thao tác với thư mục ${folder.name}`} /></Dropdown>}
                    </div>)}
                </div>}
                {fileLayout === 'list' ? <div className="workspace-table">
                    <Table
                        key={`${scope}-${filters.q || ''}`}
                        columns={columns} dataSource={rows} rowKey="id" scroll={{ x: personal ? 790 : !user ? 995 : 925 }}
                        locale={{ emptyText: <EmptyFiles searching={Boolean(filters.q)} onUpload={() => openUpload()} /> }}
                        pagination={filePagination}
                    />
                </div> : <div className="workspace-grid-view">
                    {gridRows.length ? <div className="workspace-file-grid">
                        {gridRows.map(file => <article className="workspace-file-card" key={file.id}>
                            <div className="workspace-file-card-header">
                                <FileName file={file} personal={personal} userId={user?.id} compact />
                                {columns[columns.length - 1].render(null, file, true)}
                            </div>
                            <button type="button" className="workspace-file-card-preview" aria-label={`Xem ${file.file_name}`} onClick={() => openFile(file)}>
                                <FileThumbnail key={`${scope}-${file.id}-${file.updated_at}`} file={file} scope={scope} />
                            </button>
                            <div className="workspace-file-card-meta">
                                <span>Ngày tải lên: {dateLabel(file.created_at)}</span>
                                {!personal && <Countdown expiry={file.expired_at} />}
                            </div>
                        </article>)}
                    </div> : <EmptyFiles searching={Boolean(filters.q)} onUpload={() => openUpload()} />}
                    <Pagination className="workspace-grid-pagination" {...filePagination} />
                </div>}
            </section>}

            {(personalNotes || notifications) && <section className="workspace-canvas workspace-feed" aria-label={personal ? 'Ghi chú cá nhân' : 'Thông báo chung'}>
                <div className="workspace-section-heading"><h2><FileTextOutlined />{personal ? 'Ghi chú cá nhân' : 'Thông báo'}</h2><span>{personal ? notes.length : posts.total} {personal ? 'ghi chú' : 'thông báo'}</span></div>
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
            </section>}

            <Modal title="Tạo thư mục" open={folderOpen} onCancel={() => !creatingFolder && setFolderOpen(false)} onOk={createFolder} confirmLoading={creatingFolder} okText="Tạo thư mục" cancelText="Hủy" destroyOnClose>
                <label className="workspace-field-label" htmlFor="new-folder-name">Tên thư mục</label>
                <Input id="new-folder-name" autoFocus value={folderName} onChange={event => setFolderName(event.target.value)} maxLength={255} onPressEnter={() => !creatingFolder && createFolder()} disabled={creatingFolder} status={folderError ? 'error' : undefined} />
                {folderError && <p role="alert" className="workspace-folder-error">{folderError}</p>}
            </Modal>
            <Drawer title={uploadMode === 'folder' ? 'Tải thư mục lên' : 'Tải file lên'} open={uploadOpen} onClose={() => !uploading && setUploadOpen(false)} width={440} className="workspace-upload-drawer" styles={{ wrapper: { maxWidth: '100vw' } }} maskClosable={!uploading} closable={!uploading}>
                <p className="workspace-upload-description">{personal ? 'Lưu tài liệu trong không gian cá nhân và chọn người có thể chỉnh sửa.' : 'Chia sẻ file lên không gian chung và đặt thời gian tự xóa.'}</p>
                {currentFolder && <p className="workspace-upload-description">Vị trí: <strong>{currentFolder.name}</strong></p>}
                {uploadMode === 'folder' && <p className="workspace-upload-description">Giữ nguyên các thư mục con có file. Mỗi file tối đa 100 MB. Các thiết lập bên dưới áp dụng cho mọi file được tải lên.</p>}
                <Upload.Dragger key={uploadMode} className="workspace-dropzone" directory={uploadMode === 'folder'} multiple={uploadMode === 'folder'} fileList={fileList} maxCount={uploadMode === 'folder' ? undefined : 1} disabled={uploading} onChange={({ fileList: selected }) => setFileList(uploadMode === 'folder' ? selected.map(item => ({ ...item, name: item.originFileObj?.webkitRelativePath || item.name })) : selected.slice(-1))} beforeUpload={file => {
                    if (file.size > 100 * 1024 * 1024) { message.error(`File “${file.name}” vượt quá 100 MB và sẽ không được chọn.`); return Upload.LIST_IGNORE; }
                    return false;
                }}>
                    <div className="upload-illustration">{uploadMode === 'folder' ? <FolderOpenOutlined /> : <UploadOutlined />}</div><p className="upload-title">{uploadMode === 'folder' ? 'Nhấn để chọn thư mục' : 'Kéo thả file vào đây'}</p><p className="upload-hint">{uploadMode === 'folder' ? `${fileList.length} file được chọn · Giữ cấu trúc thư mục` : 'hoặc nhấn để chọn file · Tối đa 100 MB'}</p>
                </Upload.Dragger>
                <div className="workspace-upload-settings">
                    {!personal && <><label className="workspace-field-label" htmlFor="upload-ttl">Tự động xóa file sau</label><Select id="upload-ttl" value={ttl} onChange={setTtl} options={ttlOptions} disabled={uploading} style={{ width: '100%' }} /></>}
                    {user && <FileEditorSelect value={editorIds} onChange={setEditorIds} disabled={uploading} scope={scope} />}
                    {!personal && user && <Checkbox checked={viewOnly} onChange={event => setViewOnly(event.target.checked)} disabled={uploading}>Chặn tải file đối với người không được cấp quyền</Checkbox>}
                </div>
                <Button type="primary" block icon={<UploadOutlined />} loading={uploading} disabled={!fileList.length} onClick={upload}>{uploading ? `Đang tải lên${uploadProgress == null ? '…' : ` ${uploadProgress}%`}` : 'Tải file lên'}</Button>
                <div className="workspace-upload-note"><InfoCircleOutlined /><span>{personal ? 'File cá nhân được lưu cho đến khi bạn chủ động xóa.' : 'File sẽ bị xóa vĩnh viễn khi hết thời gian lưu đã chọn.'}</span></div>
                <div className="workspace-upload-quota"><StorageQuota quota={storageQuota} /></div>
            </Drawer>
            <Suspense fallback={<Modal open title="Đang tải chức năng…" footer={null} onCancel={() => {
                setPermissionsFile(null); setMergeFile(null); setExtractOpen(false); setExistingFilesOpen(false); setNewOfficeType(null);
            }}><p role="status">Vui lòng chờ trong giây lát.</p></Modal>}>
                {permissionsFile && <FilePermissionsModal file={permissionsFile} scope={scope} onClose={() => setPermissionsFile(null)} />}
                {mergeFile && <PdfMergeModal file={mergeFile} scope={scope} onClose={() => setMergeFile(null)} />}
                {extractOpen && <PdfExtractModal open initialFile={extractFile} scope={scope} onClose={() => setExtractOpen(false)} />}
                {existingFilesOpen && currentFolder && <ExistingFilesModal key={`${scope}-${currentFolder.id}`} scope={scope} folder={currentFolder}
                    onClose={() => setExistingFilesOpen(false)} onComplete={() => {
                        setExistingFilesOpen(false);
                        router.reload({ only: ['files', 'folders', 'stats', 'storageQuota'] });
                    }} />}
                {newOfficeType && <CreateOfficeFileModal key={newOfficeType} type={newOfficeType} scope={scope} folder={currentFolder} ttlOptions={ttlOptions}
                    onClose={() => setNewOfficeType(null)} onCreated={editUrl => window.location.assign(editUrl)} />}
            </Suspense>
            <Preview file={previewFile} personal={personal} onClose={() => setPreviewFile(null)} />
        </FileWorkspace>
    );
}

function FileName({ file, personal, userId, compact = false }) {
    const ext = file.file_name.split('.').pop().toLowerCase();
    const [kind, icon] = /^(docx?|odt)$/.test(ext) ? ['doc', <FileWordOutlined />]
        : /^(xlsx?|csv|ods)$/.test(ext) ? ['sheet', <FileExcelOutlined />]
        : /^(pptx?|odp)$/.test(ext) ? ['slide', <FilePptOutlined />]
        : ext === 'pdf' ? ['pdf', <FilePdfOutlined />]
        : /^(png|jpe?g|gif|webp|svg|avif|bmp|ico)$/.test(ext) ? ['image', <FileImageOutlined />] : ['', <FileOutlined />];
    return <div className="workspace-file-name"><span className={`workspace-file-type ${kind}`}>{icon}</span><div className="workspace-file-text"><strong title={file.file_name}>{file.file_name}</strong>{!compact && <small>{file.file_size != null ? formatStorageSize(Number(file.file_size)) : ext.toUpperCase()}{personal && Number(file.user_id) !== Number(userId) ? ` · Chia sẻ bởi ${file.user?.name || 'người dùng khác'}` : ''}</small>}</div></div>;
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
