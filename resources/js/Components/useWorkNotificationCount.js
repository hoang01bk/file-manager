import { useEffect, useState } from 'react';
import axios from 'axios';

export const publishWorkNotificationCount = count => window.dispatchEvent(new CustomEvent('work-notifications-changed', { detail: Number(count) || 0 }));

export default function useWorkNotificationCount(userId, initialCount) {
    const [count, setCount] = useState(Number(initialCount) || 0);
    useEffect(() => setCount(userId ? Number(initialCount) || 0 : 0), [userId, initialCount]);
    useEffect(() => {
        if (!userId) return;
        const controller = new AbortController();
        let pending = false;
        const refresh = async () => {
            if (document.hidden || pending) return;
            pending = true;
            try {
                const { data } = await axios.get('/work-notifications/count', { signal: controller.signal });
                if (!controller.signal.aborted) setCount(data.unread_count);
            } catch (error) {
                if (!controller.signal.aborted && error.response?.status === 401) setCount(0);
            } finally { pending = false; }
        };
        const changed = event => setCount(Number(event.detail) || 0);
        const timer = setInterval(refresh, 30000);
        window.addEventListener('work-notifications-changed', changed);
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', refresh);
        refresh();
        return () => {
            clearInterval(timer); controller.abort();
            window.removeEventListener('work-notifications-changed', changed);
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [userId]);
    return userId ? count : 0;
}
