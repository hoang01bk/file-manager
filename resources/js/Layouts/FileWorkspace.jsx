import React, { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Avatar, Badge, Button, ConfigProvider, Dropdown, Input, Tooltip } from 'antd';
import viVN from 'antd/locale/vi_VN';
import { BellOutlined, CheckSquareOutlined, FileExcelOutlined, FilePptOutlined, FileTextOutlined, FileWordOutlined, GlobalOutlined, FolderAddOutlined, FolderOpenOutlined, PlusOutlined, LogoutOutlined, ReloadOutlined, SearchOutlined, UploadOutlined } from '@ant-design/icons';
import StorageQuota from '@/Components/StorageQuota';
import useWorkNotificationCount from '@/Components/useWorkNotificationCount';
import '../../css/workspace.css';

export default function FileWorkspace({ personal, notifications = false, personalNotes = false, section = 'files', newMenuItems, searchPlaceholder, storageQuota, query = '', onSearch, onUpload, onCreateFolder, onUploadFolder, onAddExistingFiles, onCreateOffice, onRefresh, refreshing, children }) {
    const { auth, workNotificationCount = 0 } = usePage().props;
    const user = auth?.user;
    const unreadCount = useWorkNotificationCount(user?.id, workNotificationCount);
    const notificationUrl = user ? '/?tab=notifications&notice=work' : '/?tab=notifications&notice=general';
    const name = auth?.display_name || user?.name || 'Khách';
    const [search, setSearch] = useState(query);
    useEffect(() => setSearch(query), [query]);
    const accountContent = <>
        <span className="workspace-session-avatar">
            <Avatar className="workspace-avatar">{name.charAt(0).toUpperCase()}</Avatar>
            <span className="workspace-session-dot" aria-hidden="true" />
        </span>
        <span className="workspace-session-copy">
            <strong>{user ? name : 'Khách'}</strong>
            <span role="status">{user ? 'Đã đăng nhập' : 'Đăng nhập'}</span>
        </span>
    </>;

    return (
        <ConfigProvider locale={viVN} theme={{
            token: {
                colorPrimary: '#1a73e8', colorText: '#202124', colorTextSecondary: '#70757a',
                colorBorder: '#dadce0', borderRadius: 10, controlHeight: 40,
                fontFamily: 'Figtree, "Segoe UI", Arial, sans-serif',
            },
            components: {
                Table: { headerBg: '#fff', headerColor: '#5f6368', rowHoverBg: '#f4f8ff', cellPaddingBlock: 14 },
                Button: { primaryShadow: 'none', defaultShadow: 'none' },
            },
        }}>
            <div className="file-workspace">
                <aside className="workspace-sidebar" aria-label="Điều hướng chính">
                    <Link href="/" className="workspace-brand">
                        <img src="/favicon.svg" width="36" height="36" alt="" />
                        <span>File Manager</span>
                    </Link>
                    <Dropdown trigger={['click']} menu={{ items: newMenuItems ?? [
                        { key: 'folder', label: 'Tạo thư mục', icon: <FolderAddOutlined />, onClick: onCreateFolder },
                        { type: 'divider' },
                        { key: 'upload-file', label: 'Tải file lên', icon: <UploadOutlined />, onClick: onUpload },
                        { key: 'upload-folder', label: 'Tải thư mục lên', icon: <FolderOpenOutlined />, onClick: onUploadFolder },
                        ...(onAddExistingFiles ? [{ key: 'existing-files', label: 'Thêm file đã upload', icon: <FileTextOutlined />, onClick: onAddExistingFiles }] : []),
                        { type: 'divider' },
                        { key: 'create-word', label: 'Tài liệu Word', icon: <FileWordOutlined style={{ color: '#1a73e8' }} />, onClick: () => onCreateOffice('docx') },
                        { key: 'create-excel', label: 'Bảng tính Excel', icon: <FileExcelOutlined style={{ color: '#188038' }} />, onClick: () => onCreateOffice('xlsx') },
                        { key: 'create-powerpoint', label: 'Bản trình chiếu PowerPoint', icon: <FilePptOutlined style={{ color: '#b06000' }} />, onClick: () => onCreateOffice('pptx') },
                    ] }}>
                        <Button className="workspace-new-button" icon={<PlusOutlined />}>Mới</Button>
                    </Dropdown>
                    <nav className="workspace-navigation">
                        <Link href="/" className={section === 'files' && !personal && !notifications ? 'active' : ''} aria-current={section === 'files' && !personal && !notifications ? 'page' : undefined}>
                            <GlobalOutlined /><span>File Sharing</span>
                        </Link>
                        <Link href="/my-files" className={personal && !personalNotes ? 'active' : ''} aria-current={personal && !personalNotes ? 'page' : undefined}>
                            <FolderOpenOutlined /><span>File cá nhân</span>
                        </Link>
                        <Link href="/my-files?tab=notes" className={personalNotes ? 'active' : ''} aria-current={personalNotes ? 'page' : undefined}>
                            <FileTextOutlined /><span>Ghi chú cá nhân</span>
                        </Link>
                        <Link href={notificationUrl} className={`workspace-notification-link ${notifications ? 'active' : ''}`} aria-current={notifications ? 'page' : undefined}>
                            <BellOutlined /><span>Thông báo</span>
                            <Badge className="workspace-notification-badge" count={unreadCount} overflowCount={99} size="small" />
                        </Link>
                        <Link href="/work" className={section === 'work' ? 'active' : ''} aria-current={section === 'work' ? 'page' : undefined}>
                            <CheckSquareOutlined /><span>Công việc</span>
                        </Link>
                    </nav>
                    <div className="workspace-sidebar-bottom">
                        <StorageQuota quota={storageQuota} compact />
                        <div className="workspace-account">
                            <Avatar className="workspace-avatar">{name.charAt(0).toUpperCase()}</Avatar>
                            <div className="workspace-account-info"><strong title={name}>{name}</strong><span>{user ? 'Tài khoản của bạn' : 'Truy cập với tư cách khách'}</span></div>
                            {user ? (
                                <Tooltip title="Đăng xuất"><Button type="text" icon={<LogoutOutlined />} aria-label="Đăng xuất" onClick={() => router.post('/logout')} /></Tooltip>
                            ) : <Link href="/login" className="workspace-login-link">Đăng nhập</Link>}
                        </div>
                    </div>
                </aside>
                <div className="workspace-body">
                    <header className="workspace-topbar">
                        {!notifications && !personalNotes && <form className="workspace-search" role="search" onSubmit={event => { event.preventDefault(); onSearch(search); }}>
                            <SearchOutlined aria-hidden="true" />
                            <Input
                                variant="borderless" value={search} onChange={event => setSearch(event.target.value)}
                                placeholder={searchPlaceholder || (personal ? 'Tìm trong file cá nhân' : 'Tìm file trong không gian chung')}
                                aria-label={section === 'work' ? 'Tìm công việc' : 'Tìm file theo tên'} maxLength={200} allowClear
                                onClear={() => onSearch('')}
                            />
                            <Button type="text" htmlType="submit" className="workspace-search-submit">Tìm</Button>
                        </form>}
                        <div className="workspace-topbar-actions">
                            <Tooltip title="Làm mới dữ liệu"><Button className="workspace-refresh" type="text" shape="circle" icon={<ReloadOutlined />} loading={refreshing} aria-label="Làm mới dữ liệu" onClick={onRefresh} /></Tooltip>
                            <Tooltip title="Thông báo"><Link href={notificationUrl} className="workspace-notification-bell" aria-label={`Thông báo, ${unreadCount} chưa đọc`}>
                                <Badge count={unreadCount} overflowCount={99} size="small"><BellOutlined /></Badge>
                            </Link></Tooltip>
                            {user ? <Dropdown trigger={['click']} menu={{ items: [
                                { key: 'name', label: name, disabled: true },
                                { key: 'logout', label: 'Đăng xuất', icon: <LogoutOutlined />, onClick: () => router.post('/logout') },
                            ] }}><button type="button" className="workspace-account-button workspace-session is-authenticated" aria-label={`Tài khoản ${name}, đã đăng nhập`} title={name}>{accountContent}</button></Dropdown>
                                : <Link href="/login" className="workspace-account-button workspace-session is-guest" aria-label="Khách, đăng nhập tài khoản">{accountContent}</Link>}
                        </div>
                    </header>
                    <main className="workspace-content">{children}</main>
                </div>
            </div>
        </ConfigProvider>
    );
}
