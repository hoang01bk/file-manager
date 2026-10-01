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
        minWidth: 170,
        display: 'inline-block',
        flexShrink: 0,
    };

    const rowStyle = {
        display: 'flex',
        alignItems: 'center',
        marginBottom: 16,
    };

    return (
        <>
            <Head title="Đăng nhập" />

            <div
                className="login-page"
            >
                {status && (
                    <div className="login-status">{status}</div>
                )}

                {/* ── Khách ── */}
                <div
                    className="login-guest-card"
                >
                    <Button
                        size="large"
                        onClick={() => router.visit('/')}
                        className="login-guest-button"
                    >
                        Đăng Nhập Là Khách
                    </Button>
                </div>

                {/* ── Login form ── */}
                <div
                    className="login-form-card"
                >
                    {/* Row: mã nhân viên */}
                    <div style={rowStyle}>
                        <span className="login-label" style={labelStyle}>Mã số nhân viên :</span>
                        <Input
                            value={employeeCode}
                            onChange={(e) => setEmployeeCode(e.target.value)}
                            onPressEnter={handleCheck}
                            style={{ flex: 1 }}
                        />
                        <Button
                            loading={checking}
                            onClick={handleCheck}
                            className="login-action-button"
                        >
                            Kiểm tra
                        </Button>
                    </div>

                    {/* Row: password */}
                    <div style={rowStyle}>
                        <span className="login-label" style={labelStyle}>Password :</span>
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
                            className="login-error"
                        >
                            ⚠ {checkError}
                        </div>
                    )}

                    {/* After successful check */}
                    {checkedUser && (
                        <>
                            {/* Row: greeting */}
                            <div style={rowStyle}>
                                <span className="login-label" style={labelStyle}>Xin chào :</span>
                                <span
                                    className="login-user-name"
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
                                    className="login-submit-button"
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
