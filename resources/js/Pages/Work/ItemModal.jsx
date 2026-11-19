import { useState } from 'react';
import axios from 'axios';
import { Alert, Form, Modal } from 'antd';
import ItemFields from './ItemFields';
import { failureText } from './shared';

export default function ItemModal({ project, onClose, onSaved }) {
    const [form] = Form.useForm();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const save = async () => {
        try {
            const values = await form.validateFields();
            setSaving(true); setError('');
            const { data } = await axios.post(`/work/projects/${project.id}/items`, { ...values, assignee_id: values.assignee_id ?? null, due_date: values.due_date || null });
            onSaved(data.id);
        } catch (failure) { if (!failure.errorFields) setError(failureText(failure)); }
        finally { setSaving(false); }
    };
    return <Modal open title="Tạo công việc" width={700} onCancel={() => !saving && onClose()} onOk={save} okText="Tạo công việc" cancelText="Hủy" confirmLoading={saving} maskClosable={!saving} closable={!saving}>
        <p className="work-muted">Dự án: {project.name}</p>
        <Form form={form} layout="vertical" disabled={saving} initialValues={{ status: 'todo', priority: 'normal', assignee_id: null }}><ItemFields project={project} /></Form>
        {error && <Alert type="error" title={error} showIcon />}
    </Modal>;
}
