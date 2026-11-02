import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Alert, Button, Checkbox, Spin } from 'antd';
import { RotateLeftOutlined, RotateRightOutlined } from '@ant-design/icons';
import { getDocument, GlobalWorkerOptions, version } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?worker&url';

GlobalWorkerOptions.workerSrc = workerUrl;
const assetBase = import.meta.env.DEV
    ? '/node_modules/pdfjs-dist/'
    : `${import.meta.env.BASE_URL}pdfjs/${version}/`;

function PageThumbnail({ pdf, number, failed, rotation }) {
    const host = useRef(null);
    const [visible, setVisible] = useState(false);
    const [status, setStatus] = useState('loading');
    const [ratio, setRatio] = useState(3 / 4);

    useEffect(() => {
        const observer = new IntersectionObserver(entries => {
            setVisible(entries.some(entry => entry.isIntersecting));
        }, { root: host.current.closest('.workspace-pdf-page-grid'), rootMargin: '250px' });
        observer.observe(host.current);
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        if (!visible || !pdf) return;
        let cancelled = false;
        let renderTask;
        let page;
        let finished = false;
        const canvas = document.createElement('canvas');
        canvas.setAttribute('aria-hidden', 'true');
        const container = host.current;
        setStatus('loading');
        const render = async () => {
            try {
                page = await pdf.getPage(number);
                if (cancelled) return;
                const angle = (page.rotate + rotation) % 360;
                const original = page.getViewport({ scale: 1, rotation: angle });
                setRatio(original.width / original.height);
                // Render at preview resolution while bounding memory per visible page.
                const width = Math.min(1600, container.clientWidth * Math.min(window.devicePixelRatio || 1, 2));
                const viewport = page.getViewport({ scale: Math.min(width / original.width, 2200 / original.height), rotation: angle });
                canvas.width = Math.ceil(viewport.width);
                canvas.height = Math.ceil(viewport.height);
                renderTask = page.render({ canvasContext: canvas.getContext('2d'), viewport });
                await renderTask.promise;
                if (cancelled) return;
                container.appendChild(canvas);
                setStatus('ready');
            } catch {
                if (!cancelled) setStatus('error');
            } finally {
                finished = true;
                page?.cleanup();
                if (cancelled) { canvas.width = 0; canvas.height = 0; }
            }
        };
        render();
        return () => {
            cancelled = true;
            renderTask?.cancel();
            canvas.remove();
            if (finished) { canvas.width = 0; canvas.height = 0; }
        };
    }, [pdf, number, visible, rotation]);

    return <span ref={host} className="workspace-pdf-thumbnail" style={{ aspectRatio: ratio }} aria-label={`Xem trước trang ${number}`}>
        {(failed || status === 'error') ? <span className="workspace-pdf-thumbnail-error">Không tải được ảnh</span>
            : visible && status !== 'ready' && <Spin size="small" />}
    </span>;
}

export default function PdfPageGrid({ source, pages, selected, disabled, onToggle, rotations, onRotate }) {
    const [pdf, setPdf] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        const controller = new AbortController();
        let loadingTask;
        const load = async () => {
            try {
                const data = typeof source === 'string'
                    ? (await axios.get(source, { responseType: 'arraybuffer', signal: controller.signal })).data
                    : await source.arrayBuffer();
                if (controller.signal.aborted) return;
                loadingTask = getDocument({
                    data, isEvalSupported: false,
                    cMapUrl: `${assetBase}cmaps/`, cMapPacked: true,
                    standardFontDataUrl: `${assetBase}standard_fonts/`, wasmUrl: `${assetBase}wasm/`,
                });
                const document = await loadingTask.promise;
                if (!controller.signal.aborted) setPdf(document);
            } catch {
                if (!controller.signal.aborted) setError('Không tải được ảnh xem trước. Bạn vẫn có thể chọn số trang hoặc chọn lại file để thử lại.');
            }
        };
        load();
        return () => { controller.abort(); loadingTask?.destroy().catch(() => {}); };
    }, [source]);

    return <>
        {error && <Alert type="warning" showIcon title={error} />}
        <div className="workspace-pdf-page-grid" role="group" aria-label="Chọn trang PDF">{pages.map(page => (
            <div className="workspace-pdf-page-item" key={page}>
                <div className="workspace-pdf-page-toolbar">
                    <span className="workspace-pdf-page-caption">Trang {page}{selected.has(page) ? ' · Đã chọn' : ' · Bấm vào trang để chọn'}</span>
                    <div className="workspace-pdf-rotate-actions">
                        <Button size="small" icon={<RotateLeftOutlined />} disabled={disabled} onClick={() => onRotate(page, -90)}
                            title="Xoay trái 90°" aria-label={`Xoay trái trang ${page} 90 độ`} />
                        <Button size="small" icon={<RotateRightOutlined />} disabled={disabled} onClick={() => onRotate(page, 90)}
                            title="Xoay phải 90°" aria-label={`Xoay phải trang ${page} 90 độ`} />
                        <Button size="small" disabled={disabled || !rotations[page]} onClick={() => onRotate(page, 0)}
                            title="Khôi phục hướng của trang gốc" aria-label={`Khôi phục hướng gốc trang ${page}`}>Gốc</Button>
                    </div>
                </div>
                <Checkbox className={`workspace-pdf-page${selected.has(page) ? ' selected' : ''}${disabled ? ' disabled' : ''}`}
                    checked={selected.has(page)} disabled={disabled} onChange={event => onToggle(page, event.target.checked)} aria-label={`Trang ${page}`}>
                    <PageThumbnail pdf={pdf} number={page} failed={!!error} rotation={rotations[page] || 0} />
                </Checkbox>
            </div>
        ))}</div>
    </>;
}
