import React from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Button, Result, Space } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';

const messages = {
    403: { title: 'Bạn không có quyền mở file này', description: 'Hãy đăng nhập đúng tài khoản hoặc liên hệ người tải lên để được cấp quyền.' },
    404: { title: 'Không tìm thấy file', description: 'File có thể đã bị xóa hoặc đường dẫn không còn đúng.' },
    410: { title: 'File đã hết hạn', description: 'Thời gian lưu trữ của file đã kết thúc. Bạn có thể quay lại danh sách để tải lên file mới.' },
    413: { title: 'File vượt quá dung lượng cho phép', description: 'Hãy sử dụng file có dung lượng nhỏ hơn để mở trong trình chỉnh sửa.' },
    415: { title: 'Định dạng file chưa được hỗ trợ', description: 'Trình chỉnh sửa hiện hỗ trợ file Word (.docx) và Excel (.xlsx).' },
    503: { title: 'Trình chỉnh sửa chưa sẵn sàng', description: 'Vui lòng quay lại danh sách và thử mở file sau.' },
};

export default function FileUnavailable({ status, backUrl }) {
    const { auth } = usePage().props;
    const message = messages[status] || messages[404];

    return (
        <main style={{ minHeight: '100dvh', display: 'grid', placeItems: 'center', padding: 24, background: '#f0f2f5' }}>
            <Head title={message.title} />
            <div style={{ width: '100%', maxWidth: 640, background: '#fff', borderRadius: 12 }}>
                <Result
                    status={status === 403 || status === 404 ? String(status) : 'warning'}
                    title={message.title}
                    subTitle={message.description}
                    extra={(
                        <Space wrap>
                            <Button type="primary" href={backUrl} icon={<ArrowLeftOutlined />}>Quay lại danh sách</Button>
                            {status === 403 && !auth?.user && <Button href="/login">Đăng nhập</Button>}
                        </Space>
                    )}
                />
            </div>
        </main>
    );
}
