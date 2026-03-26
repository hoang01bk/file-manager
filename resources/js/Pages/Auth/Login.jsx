import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Button, Input } from 'antd';

export default function Login({ status }) {
    const [employeeCode, setEmployeeCode] = useState('');
    const [password, setPassword] = useState('');

    const [checking, setChecking] = useState(false);
    const [checkedUser, setCheckedUser] = useState(null);
    const [checkError, setCheckError] = useState('');
    const [loggingIn, setLoggingIn] = useState(false);

    const handleCheck = async () => {
        if (!employeeCode || !password) {
            setCheckError('Vui lòng nhập mã nhân viên và mật khẩu');
            return;
        }
        setChecking(true);
        setCheckError('');
        setCheckedUser(null);
        try {
            const res = await axios.post('/auth/check', {
                employee_code: employeeCode,
                password,
            });
            setCheckedUser(res.data);
        } catch (e) {
            setCheckError(
                e.response?.data?.message || 'Sai mã nhân viên hoặc mật khẩu'
            );
        } finally {
            setChecking(false);
        }
    };

    const handleLogin = () => {
        setLoggingIn(true);
        router.post(
            route('login'),
            {
                employee_code: employeeCode,
                password,
                remember: false,
            },
            { onError: () => setLoggingIn(false) }
        );
    };

    const labelStyle = {
        color: 'white',
        minWidth: 170,
        display: 'inline-block',
        fontWeight: 500,
        fontSize: 15,
        flexShrink: 0,
    };

    const rowStyle = {
        display: 'flex',
        alignItems: 'center',
        marginBottom: 12,
    };

    return (
        <>
            <Head title="Đăng nhập" />

            <div
                style={{
                    minHeight: '100vh',
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    justifyContent: 'center',
                    backgroundColor: '#e8eaf0',
                    gap: 16,
                    padding: 24,
                }}
            >
                {status && (
                    <div style={{ color: '#16a34a', fontSize: 14 }}>{status}</div>
                )}

                {/* ── Khách ── */}
                <div
                    style={{
                        backgroundColor: '#f4a987',
                        borderRadius: 12,
                        padding: '24px 32px',
                        width: '100%',
                        maxWidth: 540,
                        display: 'flex',
                        justifyContent: 'center',
                    }}
                >
                    <Button
                        size="large"
                        onClick={() => router.visit('/')}
                        style={{
                            backgroundColor: 'white',
                            border: 'none',
                            fontWeight: 600,
                            fontSize: 16,
                            height: 44,
                            paddingInline: 32,
                        }}
                    >
                        Đăng Nhập Là Khách
                    </Button>
                </div>

                {/* ── Login form ── */}
                <div
                    style={{
                        backgroundColor: '#2b5ea7',
                        borderRadius: 12,
                        padding: '24px 32px',
                        width: '100%',
                        maxWidth: 540,
                    }}
                >
                    {/* Row: mã nhân viên */}
                    <div style={rowStyle}>
                        <span style={labelStyle}>Mã số nhân viên :</span>
                        <Input
                            value={employeeCode}
                            onChange={(e) => setEmployeeCode(e.target.value)}
                            onPressEnter={handleCheck}
                            style={{ flex: 1 }}
                        />
                        <Button
                            loading={checking}
                            onClick={handleCheck}
                            style={{
                                marginLeft: 8,
                                backgroundColor: '#f5a623',
                                border: 'none',
                                color: 'white',
                                fontWeight: 600,
                                flexShrink: 0,
                            }}
                        >
                            Kiểm tra
                        </Button>
                    </div>

                    {/* Row: password */}
                    <div style={rowStyle}>
                        <span style={labelStyle}>Password :</span>
                        <Input.Password
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            onPressEnter={handleCheck}
                            style={{ flex: 1 }}
                        />
                    </div>

                    {/* Error message */}
                    {checkError && (
                        <div
                            style={{
                                color: '#ffe58f',
                                marginBottom: 10,
                                fontSize: 13,
                            }}
                        >
                            ⚠ {checkError}
                        </div>
                    )}

                    {/* After successful check */}
                    {checkedUser && (
                        <>
                            {/* Row: greeting */}
                            <div style={rowStyle}>
                                <span style={labelStyle}>Xin chào :</span>
                                <span
                                    style={{
                                        color: 'white',
                                        fontWeight: 600,
                                        fontSize: 15,
                                    }}
                                >
                                    {checkedUser.name}
                                </span>
                            </div>

                            {/* Login button */}
                            <div style={{ marginTop: 16, textAlign: 'center' }}>
                                <Button
                                    loading={loggingIn}
                                    onClick={handleLogin}
                                    size="large"
                                    style={{
                                        backgroundColor: '#f5a623',
                                        border: 'none',
                                        color: 'white',
                                        fontWeight: 700,
                                        fontSize: 15,
                                        height: 48,
                                        paddingInline: 36,
                                    }}
                                >
                                    Đăng Nhập bằng tài khoản này
                                </Button>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
