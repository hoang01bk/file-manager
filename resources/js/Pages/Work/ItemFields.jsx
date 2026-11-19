import { Form, Input, Select } from 'antd';
import { memberOptions, priorities, statuses } from './shared';

export default function ItemFields({ project }) {
    return <>
        <Form.Item name="title" label="Tên công việc" rules={[{ required: true, whitespace: true, message: 'Vui lòng nhập tên công việc.' }]}><Input maxLength={200} /></Form.Item>
        <Form.Item name="description" label="Mô tả"><Input.TextArea rows={4} maxLength={10000} placeholder="Nội dung cần thực hiện, kết quả mong đợi…" /></Form.Item>
        <div className="work-form-grid">
            <Form.Item name="status" label="Trạng thái" rules={[{ required: true }]}><Select options={statuses} /></Form.Item>
            <Form.Item name="priority" label="Ưu tiên" rules={[{ required: true }]}><Select options={priorities} /></Form.Item>
            <Form.Item name="assignee_id" label="Người phụ trách"><Select placeholder="Chưa phân công" allowClear showSearch optionFilterProp="label" options={memberOptions(project)} /></Form.Item>
            <Form.Item name="due_date" label="Hạn hoàn thành"><Input type="date" /></Form.Item>
        </div>
    </>;
}
