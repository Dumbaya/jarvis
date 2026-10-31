import { redirect } from "react-router";

import { api, clearChats, tokenStore } from "~/lib/api";

export function clientLoader() {
  return redirect("/");
}

export async function clientAction() {
  try {
    await api("/auth/logout", { method: "POST" });
  } catch {
    // 서버 폐기에 실패해도 브라우저의 토큰은 반드시 지운다.
  }
  tokenStore.clear();
  clearChats();
  return redirect("/login");
}
