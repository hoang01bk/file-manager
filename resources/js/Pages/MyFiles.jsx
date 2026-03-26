import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Layout, Menu, Table, Button, Modal,
    Upload, Card, Statistic, Row, Col, message, Typography, Space, Input
} from 'antd';
import {
    DeleteOutlined, FileOutlined, EyeOutlined, CloudUploadOutlined,
    InboxOutlined, DownloadOutlined,
    UserOutlined, LogoutOutlined, GlobalOutlined, FolderOpenOutlined, SendOutlined
} from '@ant-design/icons';

const { Header, Content, Sider } = Layout;
const { Title, Text } = Typography;

export default function MyFiles({ files, userPosts = [] }) {
    const { auth } = usePage().props;
    const user = auth?.user ?? null;

    const [loading, setLoading] = useState(false);
    const [previewFile, setPreviewFile] = useState(null);
    const [postContent, setPostContent] = useState('');
    const [postLoading, setPostLoading] = useState(false);

    const handleUpload = (info) => {
        setLoading(true);
        router.post('/my-files/upload', {
            file: info.file,
        }, {
            forceFormData: true,
            onSuccess: () => {
                message.success('Tải lên thành công!');
                setLoading(false);
            },
            onError: () => {
                message.error('Lỗi khi tải file.');
                setLoading(false);
            }
        });
    };

    const handlePostSubmit = () => {
        if (!postContent.trim()) {
            message.error('Nội dung không được để trống.');
            return;
        }
        setPostLoading(true);
        router.post('/user-posts', { content: postContent }, {
            onSuccess: () => {
                setPostContent('');
                setPostLoading(false);
            },
            onError: () => {
                message.error('Lỗi khi gửi.');
                setPostLoading(false);
            },
        });
    };

    const handleDeletePost = (id) => {
        if (confirm('Bạn có chắc chắn muốn xóa ghi chú này?')) {
            router.delete(`/user-posts/${id}`);
        }
    };

    const columns = [
        {
            title: 'Tên File',
            dataIndex: 'file_name',
            key: 'file_name',
            ellipsis: true,
            render: (text) => (
                <Space>
                    <FileOutlined style={{ color: '#1890ff', fontSize: 18 }} />
                    <Text strong>{text}</Text>
                </Space>
            ),
        },
        {
            title: 'Ngày tải lên',
            dataIndex: 'created_at',
            key: 'created_at',
            width: 180,
            render: (date) => new Date(date).toLocaleString('vi-VN'),
        },
        {
            title: 'Thao tác',
            key: 'action',
            width: 220,
            render: (_, record) => (
                <Space>
                    <Button
                        type="link"
                        icon={<EyeOutlined />}
                        onClick={() => setPreviewFile(record)}
                    >
                        Xem
                    </Button>
                    <Button
                        type="link"
                        icon={<DownloadOutlined />}
                        onClick={() => window.location.href = `/my-files/${record.id}/download`}
                    >
                        Tải về
                    </Button>
                    <Button
                        type="link"
                        danger
                        icon={<DeleteOutlined />}
                        onClick={() => {
                            if (confirm('Bạn có chắc chắn muốn xóa file này?')) {
                                router.delete(`/my-files/${record.id}`);
                            }
                        }}
                    >
                        Xóa
                    </Button>
                </Space>
            ),
        },
    ];

    return (
        <Layout style={{ minHeight: '100vh' }}>
            <Head title="File cá nhân" />

            <Sider width={220} theme="dark" style={{ background: '#001628', position: 'fixed', height: '100vh', left: 0, top: 0, bottom: 0, zIndex: 100 }}>
                <div style={{ padding: '20px 16px', borderBottom: '1px solid rgba(255,255,255,0.1)' }}>
                    <Text style={{ color: '#fff', fontWeight: 600, fontSize: 15 }}>Quản lý File</Text>
                </div>
                <Menu
                    theme="dark"
                    mode="inline"
                    selectedKeys={['my-files']}
                    style={{ background: '#001628', borderRight: 0, marginTop: 8 }}
                    items={[
                        { key: 'dashboard', icon: <GlobalOutlined />, label: <a href="/">Bảng tin chung</a> },
                        { key: 'my-files', icon: <FolderOpenOutlined />, label: <a href="/my-files">File cá nhân</a> },
                    ]}
                />
                <div style={{ position: 'absolute', bottom: 0, width: '100%', padding: '16px', borderTop: '1px solid rgba(255,255,255,0.1)' }}>
                    <div style={{ color: '#bbb', marginBottom: 8, fontSize: 13, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        <UserOutlined style={{ marginRight: 6 }} />{user?.name}
                    </div>
                    <Button
                        size="small"
                        danger
                        icon={<LogoutOutlined />}
                        onClick={() => router.post('/logout')}
                        style={{ width: '100%' }}
                    >
                        Đăng xuất
                    </Button>
                </div>
            </Sider>

            <Layout style={{ background: '#f0f2f5', marginLeft: 220 }}>
                <Header style={{ background: '#fff', padding: '0 24px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                    <Title level={4} style={{ margin: 0 }}>File cá nhân</Title>
                    <Text type="secondary">Chào mừng, <strong>{user?.name}</strong></Text>
                </Header>

                <Content style={{ margin: '24px' }}>
                    <Row gutter={[24, 24]} style={{ marginBottom: 24 }}>
                        {/* Cột trái: Post cá nhân */}
                        <Col span={14} style={{ display: 'flex' }}>
                            <Card
                                title="Ghi chú cá nhân"
                                bordered={false}
                                className="shadow-sm"
                                style={{ flex: 1, display: 'flex', flexDirection: 'column' }}
                                styles={{ body: { flex: 1, display: 'flex', flexDirection: 'column' } }}
                            >
                                {/* Danh sách posts */}
                                <div style={{ flex: 1, overflowY: 'scroll', maxHeight: 380, marginBottom: 12, paddingRight: 4 }}>
                                    {userPosts.length > 0 ? userPosts.map((post) => (
                                        <div
                                            key={post.id}
                                            style={{
                                                background: '#f0f7ff',
                                                border: '1px solid #91caff',
                                                borderRadius: 8,
                                                padding: '10px 14px',
                                                marginBottom: 10,
                                            }}
                                        >
                                            <Text style={{ whiteSpace: 'pre-wrap' }}>{post.content}</Text>
                                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 6 }}>
                                                <Text type="secondary" style={{ fontSize: 11 }}>
                                                    {new Date(post.created_at).toLocaleString('vi-VN')}
                                                </Text>
                                                <Button
                                                    type="text"
                                                    danger
                                                    size="small"
                                                    icon={<DeleteOutlined />}
                                                    onClick={() => handleDeletePost(post.id)}
                                                />
                                            </div>
                                        </div>
                                    )) : (
                                        <div style={{ textAlign: 'center', padding: 32 }}>
                                            <Text type="secondary">Chưa có ghi chú nào.</Text>
                                        </div>
                                    )}
                                </div>

                                {/* Input gửi */}
                                <div style={{ marginTop: 'auto' }}>
                                    <Input.TextArea
                                        rows={2}
                                        placeholder="Nhập ghi chú..."
                                        value={postContent}
                                        onChange={(e) => setPostContent(e.target.value)}
                                        style={{ marginBottom: 8 }}
                                    />
                                    <Button
                                        type="primary"
                                        icon={<SendOutlined />}
                                        loading={postLoading}
                                        onClick={handlePostSubmit}
                                    >
                                        Gửi
                                    </Button>
                                </div>
                            </Card>
                        </Col>

                        {/* Cột phải: Stats + Upload */}
                        <Col span={10} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                            {/* Stats */}
                            <Row gutter={[12, 12]}>
                                <Col span={12}>
                                    <Card bordered={false} className="shadow-sm" styles={{ body: { padding: 16 } }}>
                                        <Statistic title="Files của tôi" value={files.length} prefix={<FileOutlined />} valueStyle={{ color: '#3f51b5' }} />
                                    </Card>
                                </Col>
                                <Col span={12}>
                                    <Card bordered={false} className="shadow-sm" styles={{ body: { padding: 16 } }}>
                                        <Statistic title="Ghi chú" value={userPosts.length} prefix={<SendOutlined />} valueStyle={{ color: '#52c41a' }} />
                                    </Card>
                                </Col>
                            </Row>

                            {/* Upload */}
                            <Card title="Tải file lên" bordered={false} className="shadow-sm" style={{ flex: 1 }}>
                                <Upload.Dragger
                                    customRequest={handleUpload}
                                    showUploadList={false}
                                    disabled={loading}
                                    style={{ background: '#fafafa', borderRadius: 12 }}
                                >
                                    <p className="ant-upload-drag-icon"><InboxOutlined style={{ color: '#1890ff' }} /></p>
                                    <p className="ant-upload-text">Nhấn hoặc kéo thả file vào đây</p>
                                    <p className="ant-upload-hint">File sẽ được lưu riêng tư, chỉ bạn mới thấy.</p>
                                </Upload.Dragger>
                                <div style={{ paddingTop: 12 }}>
                                    <Text type="secondary">* File cá nhân được lưu vĩnh viễn, chỉ bị xóa khi bạn tự xóa.</Text>
                                </div>
                            </Card>
                        </Col>
                    </Row>

                    {/* Bảng file - full width */}
                    <Card title="Danh sách file của tôi" bordered={false} className="shadow-sm">
                        <Table
                            columns={columns}
                            dataSource={files}
                            rowKey="id"
                            pagination={{ pageSize: 8, size: 'small' }}
                            scroll={{ x: 'max-content' }}
                        />
                    </Card>
                </Content>
            </Layout>

            <Modal
                title={previewFile?.file_name}
                open={!!previewFile}
                onCancel={() => setPreviewFile(null)}
                footer={null}
                width={900}
                styles={{ body: { padding: 0, height: '70vh' } }}
                destroyOnClose
            >
                {previewFile && (
                    <iframe
                        src={`/my-files/${previewFile.id}/preview`}
                        style={{ width: '100%', height: '70vh', border: 'none' }}
                    />
                )}
            </Modal>
        </Layout>
    );
}
