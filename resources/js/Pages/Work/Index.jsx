import { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Avatar, Button, Empty, Pagination, Segmented, Select, Table, Tooltip, message } from 'antd';
import { AppstoreOutlined, CalendarOutlined, CheckSquareOutlined, FlagOutlined, PlusOutlined, ProjectOutlined, SettingOutlined, TeamOutlined, UnorderedListOutlined } from '@ant-design/icons';
import FileWorkspace from '@/Layouts/FileWorkspace';
import ProjectModal from './ProjectModal';
import ItemModal from './ItemModal';
import ItemDrawer from './ItemDrawer';
import { dateLabel, failureText, isOverdue, memberOptions, priorities, PriorityTag, statuses, StatusTag } from './shared';
import '../../../css/work.css';

export default function WorkIndex({ projects, project, items, stats, filters, today, storageQuota, selectedItemId = null }) {
    const user = usePage().props.auth.user;
    const [projectModal, setProjectModal] = useState(null);
    const [newItem, setNewItem] = useState(false);
    const [selectedItem, setSelectedItem] = useState(selectedItemId);
    useEffect(() => setSelectedItem(selectedItemId), [selectedItemId]);
    const [refreshing, setRefreshing] = useState(false);
    const [moving, setMoving] = useState(null);

    const visit = (patch, page = 1) => {
        const query = { project: project?.id, ...filters, ...patch, page };
        Object.keys(query).forEach(key => { if (query[key] === null || query[key] === '' || query[key] === false || query[key] === undefined) delete query[key]; });
        if (query.overdue) query.overdue = 1;
        router.get('/work', query, { preserveState: true, preserveScroll: true, replace: true });
    };
    const refresh = () => {
        setRefreshing(true);
        router.reload({ onFinish: () => setRefreshing(false) });
    };
    const changeStatus = async (item, status) => {
        if (item.status === status) return;
        setMoving(item.id);
        try { await axios.patch(`/work/items/${item.id}`, { status, version: item.version }); refresh(); }
        catch (error) { message.error(failureText(error)); refresh(); }
        finally { setMoving(null); }
    };
    const changeProject = id => router.get('/work', { project: id, view: filters.view });
    const columns = [
        { title: 'Công việc', dataIndex: 'title', width: 330, render: (_, item) => <button type="button" className="work-item-title" onClick={() => setSelectedItem(item.id)}><span>#{item.id}</span><strong>{item.title}</strong></button> },
        { title: 'Trạng thái', dataIndex: 'status', width: 135, render: status => <StatusTag value={status} /> },
        { title: 'Ưu tiên', dataIndex: 'priority', width: 120, render: priority => <PriorityTag value={priority} /> },
        { title: 'Người phụ trách', key: 'assignee', width: 180, render: (_, item) => <span className="work-assignee"><Avatar size={25}>{item.assignee?.name.charAt(0) || '?'}</Avatar><span>{item.assignee?.name || 'Chưa phân công'}</span></span> },
        { title: 'Hạn hoàn thành', dataIndex: 'due_date', width: 160, render: (_, item) => <span className={isOverdue(item, today) ? 'work-overdue' : 'work-muted'}>{dateLabel(item.due_date)}{isOverdue(item, today) && <small>Quá hạn</small>}</span> },
    ];
    const pagination = { current: items.current_page || 1, total: items.total, pageSize: items.per_page, showSizeChanger: false, hideOnSinglePage: true, onChange: page => visit({}, page) };
    const filtered = Boolean(filters.q || filters.status || filters.priority || filters.assignee || filters.overdue);

    return <FileWorkspace section="work" storageQuota={storageQuota} query={filters.q} searchPlaceholder="Tìm công việc trong dự án" onSearch={q => visit({ q: q.trim() })} onRefresh={refresh} refreshing={refreshing}
        newMenuItems={[
            { key: 'item', label: 'Tạo công việc', icon: <CheckSquareOutlined />, disabled: !project, onClick: () => setNewItem(true) },
            { key: 'project', label: 'Tạo dự án', icon: <ProjectOutlined />, onClick: () => setProjectModal('new') },
        ]}>
        <Head title="Quản lý công việc" />
        <section className="workspace-canvas work-page" aria-label="Quản lý công việc">
            <div className="work-page-heading">
                <div><span className="work-eyebrow">KHÔNG GIAN LÀM VIỆC</span><h1>Công việc của nhóm</h1><p>Cùng lên kế hoạch, giao việc và theo dõi tiến độ.</p></div>
                <Button icon={<PlusOutlined />} onClick={() => setProjectModal('new')}>Tạo dự án</Button>
            </div>
            {project ? <>
                <div className="work-project-bar">
                    <Select aria-label="Chọn dự án" className="work-project-select" value={project.id} onChange={changeProject} showSearch optionFilterProp="label"
                        options={projects.map(entry => ({ value: entry.id, label: entry.name }))} />
                    <div className="work-project-members"><TeamOutlined /><Avatar.Group max={{ count: 4 }}>{project.members.map(member => <Tooltip title={member.name} key={member.id}><Avatar>{member.name.charAt(0)}</Avatar></Tooltip>)}</Avatar.Group><span>{project.members.length} thành viên</span></div>
                    {project.owner_id === user.id && <Button type="text" icon={<SettingOutlined />} onClick={() => setProjectModal('edit')}>Thiết lập dự án</Button>}
                </div>
                {project.description && <p className="work-project-description">{project.description}</p>}
                <div className="work-stats">
                    <div><span>Tổng công việc</span><strong>{stats.total}</strong><CheckSquareOutlined /></div>
                    <div className="is-blue"><span>Đang làm</span><strong>{stats.doing}</strong><FlagOutlined /></div>
                    <div className="is-red"><span>Quá hạn</span><strong>{stats.overdue}</strong><CalendarOutlined /></div>
                    <div className="is-green"><span>Hoàn thành</span><strong>{stats.done}</strong><CheckSquareOutlined /></div>
                </div>
                <div className="work-toolbar">
                    <Button type="primary" icon={<PlusOutlined />} onClick={() => setNewItem(true)}>Tạo công việc</Button>
                    <div className="work-view-switch"><span>{items.total} công việc{filtered ? ' phù hợp' : ''}</span><Segmented aria-label="Bố cục công việc" value={filters.view} onChange={view => visit({ view })} options={[
                        { value: 'list', icon: <UnorderedListOutlined />, label: 'Danh sách' }, { value: 'board', icon: <AppstoreOutlined />, label: 'Bảng' },
                    ]} /></div>
                </div>
                <div className="work-filters">
                    <Select aria-label="Lọc trạng thái" placeholder="Tất cả trạng thái" allowClear value={filters.status || undefined} onChange={status => visit({ status })} options={statuses} />
                    <Select aria-label="Lọc ưu tiên" placeholder="Tất cả ưu tiên" allowClear value={filters.priority || undefined} onChange={priority => visit({ priority })} options={priorities} />
                    <Select aria-label="Lọc người phụ trách" placeholder="Tất cả thành viên" allowClear value={filters.assignee || undefined} showSearch optionFilterProp="label" onChange={assignee => visit({ assignee })}
                        options={[{ value: 'unassigned', label: 'Chưa phân công' }, ...memberOptions(project).map(option => ({ ...option, value: String(option.value) }))]} />
                    <Button type={filters.assignee === String(user.id) ? 'primary' : 'default'} onClick={() => visit({ assignee: filters.assignee === String(user.id) ? null : String(user.id) })}>Việc của tôi ({stats.mine})</Button>
                    <Button danger={filters.overdue} type={filters.overdue ? 'primary' : 'default'} onClick={() => visit({ overdue: !filters.overdue })}>Quá hạn</Button>
                    {filtered && <Button type="text" onClick={() => visit({ q: '', status: null, priority: null, assignee: null, overdue: false })}>Bỏ lọc</Button>}
                </div>
                {filters.view === 'list' ? <Table className="work-table" columns={columns} dataSource={items.data} rowKey="id" scroll={{ x: 925 }} pagination={pagination}
                    locale={{ emptyText: <Empty description={filtered ? 'Không có công việc phù hợp.' : 'Chưa có công việc. Tạo việc đầu tiên cho nhóm.'} /> }} /> : <>
                    <div className="work-board" aria-label="Bảng trạng thái công việc">{statuses.map(status => {
                        const entries = items.data.filter(item => item.status === status.value);
                        return <section className={`work-column column-${status.value}`} key={status.value} aria-label={status.label}>
                            <h2><span>{status.label}</span><small>{entries.length}</small></h2>
                            {entries.map(item => <article className="work-card" key={item.id}>
                                <div className="work-card-top"><span>#{item.id}</span><PriorityTag value={item.priority} /></div>
                                <button type="button" className="work-card-title" onClick={() => setSelectedItem(item.id)}>{item.title}</button>
                                <span className="work-assignee"><Avatar size={24}>{item.assignee?.name.charAt(0) || '?'}</Avatar><span>{item.assignee?.name || 'Chưa phân công'}</span></span>
                                {item.due_date && <span className={isOverdue(item, today) ? 'work-overdue' : 'work-muted'}><CalendarOutlined /> {dateLabel(item.due_date)}{isOverdue(item, today) ? ' · Quá hạn' : ''}</span>}
                                <Select aria-label={`Đổi trạng thái công việc #${item.id}`} size="small" value={item.status} options={statuses} disabled={moving !== null} loading={moving === item.id} onChange={next => changeStatus(item, next)} />
                            </article>)}
                            {!entries.length && <p className="work-column-empty">Chưa có công việc</p>}
                        </section>;
                    })}</div>
                    {items.total > items.per_page && <div className="work-pagination"><span>Đang hiển thị {items.from}–{items.to} / {items.total} công việc</span><Pagination {...pagination} /></div>}
                </>}
            </> : <div className="work-welcome"><div className="work-welcome-icon"><ProjectOutlined /></div><h2>Bắt đầu với một dự án</h2><p>Tạo dự án, mời thành viên bằng tài khoản có sẵn và giao việc cho nhóm.<br />Các dự án chỉ hiển thị cho thành viên được thêm vào.</p><Button type="primary" size="large" icon={<PlusOutlined />} onClick={() => setProjectModal('new')}>Tạo dự án đầu tiên</Button></div>}
        </section>
        {projectModal && <ProjectModal project={projectModal === 'edit' ? project : null} user={user} onClose={() => setProjectModal(null)} onSaved={id => { setProjectModal(null); message.success('Đã lưu dự án.'); changeProject(id); }} />}
        {newItem && project && <ItemModal project={project} onClose={() => setNewItem(false)} onSaved={id => { setNewItem(false); refresh(); setSelectedItem(id); }} />}
        {selectedItem && project && <ItemDrawer key={selectedItem} id={selectedItem} project={project} user={user}
            onClose={() => { setSelectedItem(null); if (selectedItemId) visit({}, items.current_page); }} onChanged={refresh}
            onDeleted={() => { setSelectedItem(null); visit({}, items.current_page); }} />}
    </FileWorkspace>;
}
