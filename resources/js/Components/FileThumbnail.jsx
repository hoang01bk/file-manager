import { useEffect, useRef, useState } from 'react';
import { FileOutlined } from '@ant-design/icons';
import { Spin } from 'antd';

export default function FileThumbnail({ file, scope }) {
    const host = useRef(null);
    const [visible, setVisible] = useState(false);
    const [source, setSource] = useState(null);
    const [failed, setFailed] = useState(false);
    const image = /\.(avif|bmp|gif|ico|jpe?g|png|svg|webp)$/i.test(file.file_name);
    const document = /\.(docx?|odt|xlsx?|ods|csv|pptx?|odp|pdf)$/i.test(file.file_name);

    useEffect(() => {
        if (!document) return;
        const observer = new IntersectionObserver(entries => {
            if (entries.some(entry => entry.isIntersecting)) {
                setVisible(true);
                observer.disconnect();
            }
        }, { rootMargin: '100px' });
        observer.observe(host.current);
        return () => observer.disconnect();
    }, [document]);

    useEffect(() => {
        if (!visible || !document) return;
        const controller = new AbortController();
        let objectUrl;
        let retry;
        let attempts = 0;
        const load = async () => {
            try {
                const response = await fetch(`/thumbnails/${scope}/${file.id}`, { signal: controller.signal });
                if (response.status === 202 && ++attempts < 30) {
                    retry = window.setTimeout(load, 2000);
                    return;
                }
                if (!response.ok || response.status === 202) throw new Error('Thumbnail unavailable');
                const blob = await response.blob();
                if (controller.signal.aborted) return;
                objectUrl = URL.createObjectURL(blob);
                setSource(objectUrl);
            } catch {
                if (!controller.signal.aborted) setFailed(true);
            }
        };
        load();
        return () => {
            controller.abort();
            window.clearTimeout(retry);
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        };
    }, [visible, document, scope, file.id]);

    const previewUrl = scope === 'personal' ? `/my-files/${file.id}/preview` : `/files/${file.id}/preview`;
    return <span ref={host} className="workspace-thumbnail">
        {!failed && (image || source)
            ? <img src={image ? previewUrl : source} alt="" loading="lazy" decoding="async" onError={() => setFailed(true)} />
            : document && !failed ? <Spin size="small" />
                : <span className="workspace-thumbnail-fallback"><FileOutlined /><span>{file.file_name.split('.').pop().toUpperCase()}</span></span>}
    </span>;
}
