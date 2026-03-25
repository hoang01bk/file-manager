import React, { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
  Layout, Menu, Table, Tag, Button, Input, Modal,
  Upload, Select, Card, Statistic, Row, Col, message, Typography, Space
} from 'antd';
import {
  UploadOutlined, DeleteOutlined, FileOutlined, EyeOutlined,
  DashboardOutlined, CloudUploadOutlined, HourglassOutlined,
  InboxOutlined, DownloadOutlined
} from '@ant-design/icons';

const { Header, Content, Sider } = Layout;
const { Title, Text } = Typography;

export default function FileDashboard({ files, posts = [] }) {
  const [loading, setLoading] = useState(false);
  const [ttl, setTtl] = useState(1440); // 24 giờ (mặc định)
  const [postContent, setPostContent] = useState('');
  const [previewFile, setPreviewFile] = useState(null);

  const handleUpload = (info) => {
    setLoading(true);
    router.post('/upload', {
      file: info.file,
      ttl_minutes: ttl
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

  const columns = [
    {
      title: 'Tên File',
      dataIndex: 'file_name',
      key: 'file_name',
      render: (text) => (
        <Space>
          <FileOutlined style={{ color: '#1890ff', fontSize: '18px' }} />
          <Text strong>{text}</Text>
        </Space>
      ),
    },
    {
      title: 'Ngày tải lên',
      dataIndex: 'created_at',
      key: 'created_at',
      render: (date) => new Date(date).toLocaleString('vi-VN'),
    },
    {
      title: 'Tự hủy sau',
      key: 'expiry',
      render: (_, record) => <CountdownTag expiry={record.expired_at} />,
    },
    {
      title: 'Thao tác',
      key: 'action',
      width: 220,
      align: 'left',
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
            onClick={() => window.location.href = `/files/${record.id}/download`}
          >
            Tải về
          </Button>
          <Button
            type="link"
            danger
            icon={<DeleteOutlined />}
            onClick={() => {
              if (confirm('Bạn có chắc chắn muốn xóa file này?')) {
                router.delete(`/files/${record.id}`);
              }
            }}
          >
            Xóa
          </Button>
        </Space>
      ),
    },
  ];

  const handlePostSubmit = () => {
    if (!postContent.trim()) {
      message.error("Nội dung không được để trống.");
      return;
    }

    setLoading(true);
    router.post('/posts', {
      content: postContent,
      ttl_minutes: ttl
    }, {
      onSuccess: () => {
        message.success('Đã gửi Post thành công!');
        setPostContent("");
        setLoading(false);
      },
      onError: () => {
        message.error('Lỗi khi gửi Post.');
        setLoading(false);
      }
    });
  };

  return (
    <Layout style={{ minHeight: '100vh' }}>
      <Head title="Quản lý File Tập Trung" />

      <Layout style={{ background: '#f0f2f5' }}>
        <Header style={{ background: '#fff', padding: '0 24px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <Title level={4} style={{ margin: 0 }}>Trao đổi thông tin và dữ liệu tạm thời</Title>
          <Text type="secondary">Chào mừng, Developer</Text>
        </Header>

        <Content style={{ margin: '24px' }}>
          <Row gutter={[24, 24]} style={{ marginBottom: 24 }}>
            {/* Cột trái: Post */}
            <Col span={14} style={{ display: 'flex' }}>
              <Card title="Trao đổi thông tin" bordered={false} className="shadow-sm" style={{ flex: 1, display: 'flex', flexDirection: 'column' }} styles={{ body: { flex: 1, display: 'flex', flexDirection: 'column' } }}>
                <div style={{ height: 420, overflowY: 'auto', marginBottom: '12px' }}>
                  {posts.length > 0 ? posts.map((post, index) => {
                    const isLeft = index % 2 === 0;
                    return (
                      <div key={post.id} style={{ display: 'flex', justifyContent: isLeft ? 'flex-start' : 'flex-end', marginBottom: '10px' }}>
                        <div style={{
                          background: isLeft ? '#e6f7ff' : '#f6ffed',
                          border: `1px solid ${isLeft ? '#91caff' : '#b7eb8f'}`,
                          padding: '10px 14px',
                          borderRadius: isLeft ? '0 12px 12px 12px' : '12px 0 12px 12px',
                          maxWidth: '80%',
                        }}>
                          <Text strong>{post.content}</Text>
                          <br />
                          <Text type="secondary" style={{ fontSize: '11px' }}>
                            {new Date(post.created_at).toLocaleString('vi-VN')}
                            {post.expires_at && ` · Hết hạn: ${new Date(post.expires_at).toLocaleString('vi-VN')}`}
                          </Text>
                        </div>
                      </div>
                    );
                  }) : (
                    <div style={{ background: '#f5f5f5', padding: '16px', borderRadius: '8px', textAlign: 'center' }}>
                      <Text type="secondary">Chưa có thông báo nào.</Text>
                    </div>
                  )}
                </div>
                <div style={{ marginTop: 'auto' }}>
                  <Input.TextArea
                    rows={2}
                    placeholder="Nhập thông tin cần Post..."
                    onChange={(e) => setPostContent(e.target.value)}
                    value={postContent}
                    style={{ marginBottom: '8px' }}
                  />
                <Button
                  type="primary"
                  onClick={handlePostSubmit}
                  loading={loading}
                >
                  Gửi Post thông tin
                </Button>
                </div>
              </Card>
            </Col>

            {/* Cột phải: Stats + Upload */}
            <Col span={10} style={{ display: 'flex', flexDirection: 'column' }}>
              {/* Stats Cards */}
              <Row gutter={[12, 12]} style={{ marginBottom: 16 }}>
                <Col span={8}>
                  <Card bordered={false} className="shadow-sm" bodyStyle={{ padding: '16px' }}>
                    <Statistic title="Files đang lưu trữ" value={files.length} prefix={<FileOutlined />} valueStyle={{ color: '#3f51b5' }} />
                  </Card>
                </Col>
                <Col span={8}>
                  <Card bordered={false} className="shadow-sm" bodyStyle={{ padding: '16px' }}>
                    <Statistic title="Dung lượng đã dùng" value={1.8} suffix="GB" prefix={<CloudUploadOutlined />} />
                  </Card>
                </Col>
                <Col span={8}>
                  <Card bordered={false} className="shadow-sm" bodyStyle={{ padding: '16px' }}>
                    <Statistic title="Sắp hết hạn (24h)" value={files.filter(f => new Date(f.expired_at) - new Date() < 86400000).length} valueStyle={{ color: '#cf1322' }} prefix={<HourglassOutlined />} />
                  </Card>
                </Col>
              </Row>

              {/* Upload Box */}
              <Card title="Tải file lên" bordered={false} className="shadow-sm" style={{ flex: 1, display: 'flex', flexDirection: 'column' }} styles={{ body: { flex: 1, display: 'flex', flexDirection: 'column' } }}>
                <Upload.Dragger
                  customRequest={handleUpload}
                  showUploadList={false}
                  disabled={loading}
                  style={{ background: '#fafafa', borderRadius: '12px', flex: 1 }}
                >
                  <p className="ant-upload-drag-icon"><InboxOutlined style={{ color: '#1890ff' }} /></p>
                  <p className="ant-upload-text">Nhấn hoặc kéo thả file vào đây</p>
                  <p className="ant-upload-hint">Hỗ trợ tải lên tập trung, an toàn và tự động dọn dẹp.</p>
                </Upload.Dragger>
                <div style={{ padding: '16px 0 0' }}>
                  <Text strong style={{ display: 'block', marginBottom: 12 }}>Thiết lập thời gian xóa:</Text>
                  <Select
                    size="large"
                    defaultValue={1440}
                    style={{ width: '100%', marginBottom: 16 }}
                    onChange={(v) => setTtl(v)}
                    options={[
                      { value: 1, label: 'Xóa sau 1 phút' },
                      { value: 5, label: 'Xóa sau 5 phút' },
                      { value: 10, label: 'Xóa sau 10 phút' },
                      { value: 60, label: 'Xóa sau 1 giờ' },
                      { value: 1440, label: 'Xóa sau 24 giờ' },
                      { value: 10080, label: 'Xóa sau 7 ngày' },
                    ]}
                  />
                  <Text type="secondary">
                    * File sẽ bị xóa vĩnh viễn khỏi server sau khoảng thời gian này.
                  </Text>
                </div>
              </Card>
            </Col>
          </Row>

          {/* Danh sách File - 100% width */}
          <Card title="Dữ liệu tạm thời" bordered={false} className="shadow-sm">
            <Table
              columns={columns}
              dataSource={files}
              rowKey="id"
              pagination={{ pageSize: 4, size: 'small' }}
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
        bodyStyle={{ padding: 0, height: '70vh' }}
        destroyOnClose
      >
        {previewFile && (
          <iframe
            src={`/files/${previewFile.id}/preview`}
            style={{ width: '100%', height: '70vh', border: 'none' }}
          />
        )}
      </Modal>
    </Layout>
  );
}

// Giữ nguyên component đếm ngược
function CountdownTag({ expiry }) {
  const [time, setTime] = useState("");
  const [color, setColor] = useState("green");

  useEffect(() => {
    const tick = () => {
      const diff = new Date(expiry) - new Date();
      if (diff <= 0) return setTime("Đang dọn dẹp...");

      const h = Math.floor(diff / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000) / 1000);

      if (h < 1) setColor("red");
      else if (h < 24) setColor("orange");

      setTime(`${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`);
    };
    const timer = setInterval(tick, 1000);
    tick();
    return () => clearInterval(timer);
  }, [expiry]);

  return <Tag color={color} style={{ fontWeight: 'bold', borderRadius: '10px' }}>{time}</Tag>;
}