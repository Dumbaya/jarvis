import { redirect } from "react-router";

import { AuthForm } from "~/components/auth-form";
import { api, ApiError, redirectIfAuthenticated, tokenStore, type AuthResponse } from "~/lib/api";
import type { Route } from "./+types/login";

export const meta: Route.MetaFunction = () => [{ title: "로그인 | Jarvis" }];

export function clientLoader() {
  redirectIfAuthenticated();
  return null;
}

export async function clientAction({ request }: Route.ClientActionArgs) {
  const form = await request.formData();
  try {
    const res = await api<AuthResponse>("/auth/login", {
      method: "POST",
      body: { email: form.get("email"), password: form.get("password") },
    });
    tokenStore.set(res.token);
    return redirect("/");
  } catch (e) {
    if (e instanceof ApiError) return { error: e.message, errors: e.errors };
    throw e;
  }
}

export default function Login() {
  return (
    <AuthForm
      title="Jarvis에 로그인"
      subtitle="내 서비스를 한곳에서 관리하세요"
      fields={[
        { name: "email", label: "이메일", type: "email", autoComplete: "email" },
        { name: "password", label: "비밀번호", type: "password", autoComplete: "current-password" },
      ]}
      submitLabel="로그인"
      footer={{ text: "계정이 없으신가요?", linkLabel: "회원가입", to: "/register" }}
    />
  );
}
