import { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Avatar, Button, Drawer, Empty, Form, Input, Popconfirm, Spin, message } from 'antd';
import { DeleteOutlined, ReloadOutlined, SendOutlined } from '@ant-design/icons';
import ItemFields from './ItemFields';
import { dateLabel, failureText, priorityLabel, statusLabel } from './shared';

function updateText(changes = {}) {
    if (changes.created) return 'Đã tạo công việc.';
    const names = { title: 'Tên công việc', status: 'Trạng thái', priority: 'Ưu tiên', assignee: 'Người phụ trách', due_date: 'Hạn hoàn thành' };
    return Object.entries(changes).map(([key, value]) => {
        if (key === 'description') return 'Đã cập nhật mô tả.';
        const format = input => key === 'status' ? statusLabel(input) : key === 'priority' ? priorityLabel(input) : key === 'due_date' ? dateLabel(input) : input || 'Chưa phân công';
        return `${names[key] || key}: ${format(value.from)} → ${format(value.to)}`;
    }).join('\n');
}

export default function ItemDrawer({ id, project, user, onClose, onChanged, onDeleted }) {
    const [form] = Form.useForm();
    const [item, setItem] = useState(null);
    const [updates, setUpdates] = useState([]);
    const [historyPage, setHistoryPage] = useState(1);
    const [hasMore, setHasMore] = useState(false);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [posting, setPosting] = useState(false);
    const [comment, setComment] = useState('');
    const [error, setError] = useState('');

    const load = async () => {
        setLoading(true); setError('');
        try {
            const { data } = await axios.get(`/work/items/${id}`);
            setItem(data.item); setUpdates(data.updates.data); setHistoryPage(1); setHasMore(Boolean(data.updates.next_page_url));
            form.setFieldsValue({ ...data.item, due_date: data.item.due_date || '', assignee_id: data.item.assignee_id ?? undefined });
        } catch (failure) { if ([403, 404].includes(failure.response?.status)) setItem(null); setError(failureText(failure)); }
        finally { setLoading(false); }
    };
    useEffect(() => { load(); }, [id]);

    const save = async () => {
        try {
            const values = await form.validateFields();
            setSaving(true); setError('');
            await axios.patch(`/work/items/${id}`, { ...values, version: item.version, assignee_id: values.assignee_id ?? null, due_date: values.due_date || null });
            message.success('Đã lưu công việc.');
            await load(); onChanged();
        } catch (failure) { if (!failure.errorFields) setError(failureText(failure)); }
        finally { setSaving(false); }
    };
    const post = async () => {
        if (!comment.trim()) return;
        setPosting(true); setError('');
        try {
            const { data } = await axios.post(`/work/items/${id}/comments`, { body: comment.trim() });
            setUpdates(previous => [data.update, ...previous]); setComment('');
        } catch (failure) { setError(failureText(failure)); }
        finally { setPosting(false); }
    };
    const more = async () => {
        setHistoryLoading(true);
        try {
            const { data } = await axios.get(`/work/items/${id}`, { params: { history_page: historyPage + 1 } });
            setUpdates(previous => [...new Map([...previous, ...data.updates.data].map(update => [update.id, update])).values()]);
            setHistoryPage(historyPage + 1); setHasMore(Boolean(data.updates.next_page_url));
        } catch (failure) { setError(failureText(failure)); }
        finally { setHistoryLoading(false); }
    };
    const remove = async () => {
        setSaving(true);
        try { await axios.delete(`/work/items/${id}`); message.success('Đã xóa công việc.'); onDeleted(); }
        catch (failure) { setError(failureText(failure)); }
        finally { setSaving(false); }
    };
    return <Drawer open title={`Công việc #${id}`} width={740} onClose={() => !saving && !posting && onClose()} maskClosable={!saving && !posting} closable={!saving && !posting} styles={{ wrapper: { maxWidth: '100vw' } }}>
        {error && <Alert type="error" title={error} showIcon action={<Button size="small" icon={<ReloadOutlined />} onClick={load} disabled={saving || posting}>Tải lại</Button>} style={{ marginBottom: 16 }} />}
        {loading ? <div className="work-loading"><Spin /></div> : item ? <>
            <p className="work-muted">{project.name} · Tạo bởi {item.creator?.name || 'Thành viên đã xóa'} · {new Date(item.created_at).toLocaleString('vi-VN')}</p>
            <Form form={form} layout="vertical" disabled={saving}><ItemFields project={project} /></Form>
            <div className="work-drawer-actions">
                <Button type="primary" onClick={save} loading={saving}>Lưu thay đổi</Button>
                {(user.id === project.owner_id || user.id === item.creator_id) && <Popconfirm title="Xóa công việc và toàn bộ bình luận?" onConfirm={remove} okText="Xóa" cancelText="Hủy"><Button danger icon={<DeleteOutlined />} disabled={saving || posting}>Xóa công việc</Button></Popconfirm>}
            </div>
            <section className="work-history" aria-label="Bình luận và lịch sử cập nhật">
                <h2>Trao đổi & lịch sử</h2>
                <Input.TextArea aria-label="Bình luận công việc" placeholder="Viết bình luận cho nhóm…" value={comment} onChange={event => setComment(event.target.value)} maxLength={10000} autoSize={{ minRows: 3, maxRows: 8 }} disabled={posting} />
                <Button type="primary" icon={<SendOutlined />} onClick={post} loading={posting} disabled={!comment.trim()} style={{ margin: '12px 0 20px' }}>Gửi bình luận</Button>
                {updates.map(update => <article className="work-update" key={update.id}>
                    <Avatar size={30}>{(update.author?.name || '?').charAt(0)}</Avatar>
                    <div><strong>{update.author?.name || 'Thành viên đã xóa'}</strong><time>{new Date(update.created_at).toLocaleString('vi-VN')}</time><p>{update.body || updateText(update.changes)}</p></div>
                </article>)}
                {hasMore && <Button onClick={more} loading={historyLoading}>Xem lịch sử trước đó</Button>}
            </section>
        </> : !error && <Empty description="Không tìm thấy công việc." />}
    </Drawer>;
}
