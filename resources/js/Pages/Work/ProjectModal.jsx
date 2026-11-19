import { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Form, Input, Modal, Select } from 'antd';
import { failureText } from './shared';

export default function ProjectModal({ project, user, onClose, onSaved }) {
    const [form] = Form.useForm();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(false);
    const [people, setPeople] = useState(project?.members || []);
    const ownerId = project?.owner_id || user.id;
    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(() => {
            setLoading(true);
            axios.get('/work/people', { params: { q: search }, signal: controller.signal })
                .then(({ data }) => setPeople(previous => [...new Map([...previous, ...data].map(person => [person.id, person])).values()]))
                .catch(() => { if (!controller.signal.aborted) setError('Không tìm được thành viên. Vui lòng thử lại.'); })
                .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        }, 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [search]);

    const save = async () => {
        try {
            const values = await form.validateFields();
            setSaving(true); setError('');
            const response = project ? await axios.patch(`/work/projects/${project.id}`, values) : await axios.post('/work/projects', values);
            onSaved(project?.id || response.data.id);
        } catch (failure) {
            if (!failure.errorFields) setError(failureText(failure));
        } finally { setSaving(false); }
    };

    return <Modal open title={project ? 'Thiết lập dự án' : 'Tạo dự án'} onCancel={() => !saving && onClose()} onOk={save}
        okText={project ? 'Lưu thay đổi' : 'Tạo dự án'} cancelText="Hủy" confirmLoading={saving} maskClosable={!saving} closable={!saving}>
        <Form form={form} layout="vertical" disabled={saving} initialValues={{ name: project?.name || '', description: project?.description || '', member_ids: project?.members?.filter(member => member.id !== ownerId).map(member => member.id) || [] }}>
            <Form.Item name="name" label="Tên dự án" rules={[{ required: true, whitespace: true, message: 'Vui lòng nhập tên dự án.' }]}><Input maxLength={150} autoFocus /></Form.Item>
            <Form.Item name="description" label="Mô tả"><Input.TextArea rows={3} maxLength={5000} /></Form.Item>
            <Form.Item name="member_ids" label="Thành viên" extra="Chủ dự án luôn là thành viên. Chỉ thành viên mới xem và cập nhật được công việc.">
                <Select mode="multiple" placeholder="Tìm theo tên hoặc mã nhân viên" showSearch filterOption={(input, option) => option.label.toLowerCase().includes(input.toLowerCase())}
                    onSearch={setSearch} loading={loading} maxCount={100} options={people.filter(person => person.id !== ownerId).map(person => ({ value: person.id, label: `${person.name} (${person.employee_code})` }))} />
            </Form.Item>
            {project && <p className="work-muted">Khi bỏ một thành viên, các việc đang giao cho họ sẽ trở thành chưa phân công.</p>}
        </Form>
        {error && <Alert type="error" title={error} showIcon />}
    </Modal>;
}
