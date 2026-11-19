import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Alert, Avatar, Button, Empty, Pagination, Segmented, Spin, Tooltip } from 'antd';
import { BellOutlined, CheckOutlined, ReloadOutlined } from '@ant-design/icons';
import { failureText } from '@/Pages/Work/shared';
import { publishWorkNotificationCount } from './useWorkNotificationCount';

export default function WorkNotificationList() {
    const [rows, setRows] = useState([]);
    const [page, setPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [unread, setUnread] = useState(false);
    const [unreadCount, setUnreadCount] = useState(0);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(null);
    const [error, setError] = useState('');
    const [revision, setRevision] = useState(0);
    const refresh = () => setRevision(value => value + 1);
    const updateCount = count => { setUnreadCount(count); publishWorkNotificationCount(count); };

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true); setError('');
        axios.get('/work-notifications', { params: { page, unread: unread ? 1 : 0 }, signal: controller.signal })
            .then(({ data }) => {
                setRows(data.notifications.data); setTotal(data.notifications.total); setPage(data.notifications.current_page);
                updateCount(data.unread_count);
            })
            .catch(failure => { if (!controller.signal.aborted) setError(failureText(failure)); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [page, unread, revision]);

    const read = async (notice, open = false) => {
        if (busy !== null) return;
        setBusy(notice.id); setError('');
        try {
            if (!notice.read_at) {
                const { data } = await axios.patch(`/work-notifications/${notice.id}/read`);
                updateCount(data.unread_count);
                if (!open) refresh();
            }
            if (open) router.visit(notice.url);
        } catch (failure) { setError(failureText(failure)); if (failure.response?.status === 404) refresh(); }
        finally { setBusy(null); }
    };
    const readAll = async () => {
        setBusy('all'); setError('');
        try {
            const { data } = await axios.patch('/work-notifications/read-all');
            updateCount(data.unread_count); refresh();
        } catch (failure) { setError(failureText(failure)); }
        finally { setBusy(null); }
    };

    return <section className="workspace-canvas workspace-work-notifications" aria-label="Thông báo công việc">
        <div className="workspace-notification-heading">
            <div><h2><BellOutlined />Thông báo công việc</h2><p>{unreadCount} thông báo chưa đọc</p></div>
            <div className="workspace-notification-actions">
                <Tooltip title="Làm mới thông báo"><Button icon={<ReloadOutlined />} aria-label="Làm mới thông báo" onClick={refresh} disabled={loading || busy !== null} /></Tooltip>
                <Button icon={<CheckOutlined />} onClick={readAll} loading={busy === 'all'} disabled={!unreadCount || loading || busy !== null}>Đánh dấu tất cả đã đọc</Button>
            </div>
        </div>
        <Segmented value={unread ? 'unread' : 'all'} onChange={value => { setUnread(value === 'unread'); setPage(1); }} disabled={busy !== null}
            options={[{ value: 'all', label: 'Tất cả' }, { value: 'unread', label: 'Chưa đọc' }]} />
        {error && <Alert type="error" title={error} showIcon style={{ marginTop: 16 }} />}
        {loading ? <div className="workspace-notification-loading"><Spin /></div> : rows.length ? <div className="workspace-notification-list">
            {rows.map(notice => <article key={notice.id} className={`workspace-notification-row ${notice.read_at ? '' : 'is-unread'}`}>
                <Avatar className="workspace-notification-avatar">{notice.actor.charAt(0)}</Avatar>
                <button type="button" className="workspace-notification-open" onClick={() => read(notice, true)} disabled={busy !== null}>
                    <span className="workspace-notification-actor"><strong>{notice.actor}</strong> {notice.action}{!notice.read_at && <i aria-label="Chưa đọc" />}</span>
                    <strong className="workspace-notification-item">#{notice.item.id} · {notice.item.title}</strong>
                    <span className="workspace-notification-summary">{notice.summary}</span>
                    <span className="workspace-notification-meta">{notice.project.name} · {new Date(notice.created_at).toLocaleString('vi-VN')}</span>
                </button>
                {!notice.read_at && <Tooltip title="Đánh dấu đã đọc"><Button type="text" icon={<CheckOutlined />} aria-label={`Đánh dấu thông báo #${notice.id} đã đọc`} onClick={() => read(notice)} disabled={busy !== null} loading={busy === notice.id} /></Tooltip>}
            </article>)}
        </div> : <Empty style={{ padding: '45px 0' }} description={unread ? 'Bạn đã đọc hết thông báo.' : 'Chưa có thông báo. Các thay đổi công việc liên quan đến bạn sẽ xuất hiện tại đây.'} />}
        <Pagination current={page} total={total} pageSize={20} onChange={setPage} hideOnSinglePage showSizeChanger={false} responsive disabled={busy !== null} />
    </section>;
}
