import React from 'react';
import { Button } from 'antd';
import { EditOutlined, LoginOutlined } from '@ant-design/icons';

export const isOfficeFile = (name = '') => /\.(docx|xlsx)$/i.test(name);
export const officeUrl = (scope, id, edit = false) => `/office/${scope}/${id}${edit ? '?edit=1' : ''}`;
export const officeLoginUrl = id => `/office/shared/${id}/login`;

export default function OfficeFileActions({ file, scope, canEdit, guest = false }) {
    if (!isOfficeFile(file.file_name)) return null;
    const publiclyEditable = file.all_users_can_edit || (file.user_id == null && !file.view_only);
    if (guest && scope === 'shared' && publiclyEditable) {
        return <Button type="link" icon={<LoginOutlined />} href={officeLoginUrl(file.id)}>
            Đăng nhập để chỉnh sửa
        </Button>;
    }
    if (!canEdit) return null;

    return (
        <Button type="link" icon={<EditOutlined />} href={officeUrl(scope, file.id, true)} target="_blank" rel="noopener noreferrer">
            Chỉnh sửa
        </Button>
    );
}
