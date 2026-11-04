import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Alert, Button, Drawer, Empty, List, Space, Tag, Typography } from 'antd';
import { BellOutlined, HistoryOutlined } from '@ant-design/icons';

export default function OfficeActivityPanel({ config, fileName, expiresAt, expired }) {
    const [open, setOpen] = useState(false);
    const [rows, setRows] = useState([]);
    const [connected, setConnected] = useState(false);
    const [error, setError] = useState('');
    const [permission, setPermission] = useState(() => window.Notification?.permission || 'unsupported');
    const notification = useRef(null);
    const endpoint = `/office/${config.scope}/${config.fileId}/activities`;

    useEffect(() => {
        if (expired) return;
        let cancelled = false;
        let pusher;
        let cursor = config.cursor;
        let fetching = false;
        let again = false;
        let blocked = false;
        const abort = new AbortController();
        const merge = incoming => setRows(previous => [...new Map([...previous, ...incoming].map(row => [row.id, row])).values()]
            .sort((a, b) => b.id - a.id).slice(0, 50));
        const showNotification = async row => {
            if (row.is_self || Notification.permission !== 'granted' || (document.visibilityState === 'visible' && document.hasFocus())) return;
            const show = () => {
                if (cancelled || Date.now() >= Date.parse(expiresAt)) return;
                const storageKey = `office-notified:${config.scope}:${config.fileId}`;
                try {
                    if (Number(localStorage.getItem(storageKey) || 0) >= row.id) return;
                    localStorage.setItem(storageKey, String(row.id));
                } catch { /* Notifications still work if browser storage is disabled. */ }
                notification.current?.close();
                const current = new Notification('Tài liệu đã được cập nhật', {
                    body: `${row.actor_name} đã lưu thay đổi vào ${fileName}.`,
                    tag: `office:${config.scope}:${config.fileId}`,
                });
                notification.current = current;
                current.onclick = () => { window.focus(); current.close(); };
            };
            try {
                if (navigator.locks) await navigator.locks.request(`office-notify:${config.scope}:${config.fileId}`, show);
                else show();
            } catch { /* OS/browser notification restrictions must not affect the editor. */ }
        };
        const catchUp = async () => {
            if (cancelled || blocked) return;
            if (Date.now() >= Date.parse(expiresAt)) { pusher?.disconnect(); return; }
            if (fetching) { again = true; return; }
            fetching = true;
            let newestOther;
            try {
                do {
                    again = false;
                    const { data } = await axios.get(endpoint, { params: { after: cursor }, signal: abort.signal });
                    if (cancelled) return;
                    merge(data.activities);
                    data.activities.forEach(row => {
                        cursor = Math.max(cursor, row.id);
                        if (!row.is_self) newestOther = row;
                    });
                    if (data.has_more) again = true;
                } while (again && !cancelled);
                setError('');
                if (newestOther && 'Notification' in window) await showNotification(newestOther);
            } catch (requestError) {
                if (cancelled) return;
                if ([403, 404, 410, 419].includes(requestError.response?.status)) {
                    blocked = true;
                    pusher?.disconnect();
                    setConnected(false);
                    setError('Không còn quyền theo dõi hoặc phiên đã hết hạn. Vui lòng mở lại tài liệu từ danh sách.');
                }
            } finally { fetching = false; }
        };
        const start = async () => {
            try {
                const { default: Pusher } = await import('pusher-js');
                if (cancelled) return;
                const secure = window.location.protocol === 'https:';
                pusher = new Pusher(config.key, {
                    cluster: 'local', wsHost: window.location.hostname,
                    wsPort: Number(window.location.port || 80), wssPort: Number(window.location.port || 443),
                    wsPath: '/office-ws', forceTLS: secure, enabledTransports: ['ws', 'wss'], disableStats: true,
                    channelAuthorization: {
                        customHandler: ({ socketId, channelName }, callback) => {
                            axios.post('/office/broadcast/auth', { socket_id: socketId, channel_name: channelName }, { signal: abort.signal })
                                .then(({ data }) => callback(null, data)).catch(error => callback(error, null));
                        },
                    },
                });
                pusher.connection.bind('state_change', ({ current }) => { if (!cancelled && current !== 'connected') setConnected(false); });
                const channel = pusher.subscribe(`private-office.${config.scope}.${config.fileId}`);
                channel.bind('pusher:subscription_succeeded', () => { if (!cancelled) setConnected(true); catchUp(); });
                channel.bind('file.saved', catchUp);
                channel.bind('pusher:subscription_error', () => {
                    if (!cancelled) { setConnected(false); setError('Không đăng ký được thông báo của tài liệu. Hãy thử mở lại trang.'); }
                });
            } catch {
                if (!cancelled) setError('Chưa kết nối được dịch vụ thông báo.');
            }
        };
        start();
        // Reconcile after reconnect/sleep; also recheck access for long-lived subscriptions.
        const interval = window.setInterval(catchUp, 60000);
        const onFocus = () => { notification.current?.close(); catchUp(); };
        window.addEventListener('focus', onFocus);
        window.addEventListener('online', catchUp);
        return () => {
            cancelled = true; abort.abort(); clearInterval(interval); pusher?.disconnect();
            window.removeEventListener('focus', onFocus); window.removeEventListener('online', catchUp);
            notification.current?.close();
        };
    }, [config.scope, config.fileId, config.key, config.cursor, endpoint, fileName, expiresAt, expired]);

    const history = async () => {
        setOpen(true);
        try {
            const { data } = await axios.get(endpoint);
            setRows(data.activities.slice().reverse());
        } catch { setError('Không tải được lịch sử. Vui lòng kiểm tra quyền truy cập và kết nối.'); }
    };
    const enableNotifications = async () => {
        try { setPermission(await Notification.requestPermission()); }
        catch { setError('Trình duyệt không cho phép bật thông báo tại địa chỉ này.'); }
    };

    return <>
        <Space wrap>
            <Button icon={<HistoryOutlined />} onClick={history} disabled={expired}>Lịch sử chỉnh sửa</Button>
            <Button icon={<BellOutlined />} onClick={enableNotifications}
                disabled={expired || !window.isSecureContext || permission !== 'default'}
                title={!window.isSecureContext ? 'Thông báo cần HTTPS hoặc localhost.' : permission === 'denied' ? 'Bạn cần cho phép thông báo trong cài đặt trình duyệt.' : undefined}>
                {permission === 'granted' ? 'Đã bật thông báo' : 'Bật thông báo'}
            </Button>
        </Space>
        <Drawer title="Lịch sử chỉnh sửa" open={open} onClose={() => setOpen(false)} width={440}>
            <Tag color={connected ? 'green' : 'orange'}>{connected ? 'Đang nhận cập nhật' : 'Chưa kết nối realtime'}</Tag>
            {error && <Alert type="warning" showIcon title={error} style={{ marginTop: 12 }} />}
            {permission === 'denied' && <Typography.Paragraph type="secondary">Thông báo đang bị chặn. Bạn có thể bật lại trong quyền của trang trên trình duyệt.</Typography.Paragraph>}
            {!rows.length ? <Empty description="Chưa có lịch sử chỉnh sửa" /> : <List dataSource={rows} renderItem={row => (
                <List.Item><List.Item.Meta title={`${row.actor_name}${row.is_self ? ' (bạn)' : ''} đã lưu thay đổi`}
                    description={`${new Date(row.saved_at).toLocaleString('vi-VN')} · ${row.kind === 'force_save' ? 'Lưu trong phiên' : 'Lưu khi kết thúc phiên'}`} /></List.Item>
            )} />}
        </Drawer>
    </>;
}
