import React from 'react';
import { Button } from 'antd';
import { EditOutlined } from '@ant-design/icons';

export const isOfficeFile = (name = '') => /\.(docx|xlsx)$/i.test(name);
export const officeUrl = (scope, id, edit = false) => `/office/${scope}/${id}${edit ? '?edit=1' : ''}`;

export default function OfficeFileActions({ file, scope, canEdit }) {
    if (!canEdit || !isOfficeFile(file.file_name)) return null;

    return (
        <Button type="link" icon={<EditOutlined />} href={officeUrl(scope, file.id, true)} target="_blank" rel="noopener noreferrer">
            Chỉnh sửa
        </Button>
    );
}
