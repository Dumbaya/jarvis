import { redirect } from "react-router";

import { AuthForm } from "~/components/auth-form";
import { api, ApiError, redirectIfAuthenticated, tokenStore, type AuthResponse } from "~/lib/api";
import type { Route } from "./+types/register";

export const meta: Route.MetaFunction = () => [{ title: "회원가입 | Jarvis" }];

export function clientLoader() {
  redirectIfAuthenticated();
  return null;
}

export async function clientAction({ request }: Route.ClientActionArgs) {
  const form = await request.formData();
  try {
    const res = await api<AuthResponse>("/auth/register", {
      method: "POST",
      body: {
        name: form.get("name"),
        email: form.get("email"),
        password: form.get("password"),
        password_confirmation: form.get("password_confirmation"),
      },
    });
    tokenStore.set(res.token);
    return redirect("/");
  } catch (e) {
    if (e instanceof ApiError) return { error: e.message, errors: e.errors };
    throw e;
  }
}

export default function Register() {
  return (
    <AuthForm
      title="계정 만들기"
      subtitle="몇 초면 시작할 수 있어요"
      fields={[
        { name: "name", label: "이름", autoComplete: "name" },
        { name: "email", label: "이메일", type: "email", autoComplete: "email" },
        { name: "password", label: "비밀번호 (8자 이상)", type: "password", autoComplete: "new-password" },
        { name: "password_confirmation", label: "비밀번호 확인", type: "password", autoComplete: "new-password" },
      ]}
      submitLabel="가입하기"
      footer={{ text: "이미 계정이 있으신가요?", linkLabel: "로그인", to: "/login" }}
    />
  );
}
