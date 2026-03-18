import React, { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
  Layout, Menu, Table, Tag, Button,
  Upload, Select, Card, Statistic, Row, Col, message, Typography, Space
} from 'antd';
import {
  UploadOutlined, DeleteOutlined, FileOutlined,
  DashboardOutlined, CloudUploadOutlined, HourglassOutlined,
  InboxOutlined, DownloadOutlined
} from '@ant-design/icons';

const { Header, Content, Sider } = Layout;
const { Title, Text } = Typography;

export default function FileDashboard({ files }) {
  const [loading, setLoading] = useState(false);
  const [ttl, setTtl] = useState(1440); // 24 giờ (mặc định)

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

  return (
    <Layout style={{ minHeight: '100vh' }}>
      <Head title="Quản lý File Tập Trung" />

      {/* Sidebar giống bản mô tả */}
      <Sider theme="dark" width={260} style={{ background: '#001529' }}>
        <div style={{ padding: '24px', textAlign: 'center' }}>
          <Title level={3} style={{ color: '#fff', margin: 0 }}>
            <CloudUploadOutlined /> FileCentral
          </Title>
        </div>
        <Menu theme="dark" mode="inline" defaultSelectedKeys={['1']} style={{ padding: '0 8px' }}>
          <Menu.Item key="1" icon={<DashboardOutlined />}>Dashboard</Menu.Item>
          {/* <Menu.Item key="2" icon={<FileOutlined />}>Tất cả File</Menu.Item> */}
        </Menu>
      </Sider>

      <Layout style={{ background: '#f0f2f5' }}>
        <Header style={{ background: '#fff', padding: '0 24px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <Title level={4} style={{ margin: 0 }}>Hệ thống lưu trữ dữ liệu</Title>
          <Text type="secondary">Chào mừng, Developer</Text>
        </Header>

        <Content style={{ margin: '24px' }}>
          {/* Stats Cards - Giống bản HTML mô phỏng */}
          <Row gutter={[24, 24]} style={{ marginBottom: 24 }}>
            <Col span={8}>
              <Card bordered={false} className="shadow-sm">
                <Statistic title="Files đang lưu trữ" value={files.length} prefix={<FileOutlined />} valueStyle={{ color: '#3f51b5' }} />
              </Card>
            </Col>
            <Col span={8}>
              <Card bordered={false} className="shadow-sm">
                <Statistic title="Dung lượng đã dùng" value={1.8} suffix="GB" prefix={<CloudUploadOutlined />} />
              </Card>
            </Col>
            <Col span={8}>
              <Card bordered={false} className="shadow-sm">
                <Statistic title="Sắp hết hạn (24h)" value={files.filter(f => new Date(f.expired_at) - new Date() < 86400000).length} valueStyle={{ color: '#cf1322' }} prefix={<HourglassOutlined />} />
              </Card>
            </Col>
          </Row>

          {/* Upload Box thiết kế lại giống Dropzone */}
          <Card style={{ marginBottom: 24 }} bordered={false} className="shadow-sm">
            <Row gutter={24} align="middle">
              <Col span={16}>
                <Upload.Dragger
                  customRequest={handleUpload}
                  showUploadList={false}
                  disabled={loading}
                  style={{ background: '#fafafa', borderRadius: '12px' }}
                >
                  <p className="ant-upload-drag-icon"><InboxOutlined style={{ color: '#1890ff' }} /></p>
                  <p className="ant-upload-text">Nhấn hoặc kéo thả file vào khu vực này để tải lên</p>
                  <p className="ant-upload-hint">Hỗ trợ tải lên tập trung, an toàn và tự động dọn dẹp.</p>
                </Upload.Dragger>
              </Col>
              <Col span={8}>
                <div style={{ padding: '10px' }}>
                  <Text strong block style={{ marginBottom: 12 }}>Thiết lập thời gian xóa:</Text>
                  <Select
                    size="large"
                    defaultValue={1440}
                    style={{ width: '100%', marginBottom: 20 }}
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
                  <Text type="secondary" size="small">
                    * File sẽ bị xóa vĩnh viễn khỏi server sau khoảng thời gian này.
                  </Text>
                </div>
              </Col>
            </Row>
          </Card>

          {/* Danh sách File */}
          <Card title="Danh sách dữ liệu" bordered={false} className="shadow-sm">
            <Table
              columns={columns}
              dataSource={files}
              rowKey="id"
              pagination={{ pageSize: 6 }}
            />
          </Card>
        </Content>
      </Layout>
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