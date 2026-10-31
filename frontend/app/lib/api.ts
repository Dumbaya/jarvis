import { redirect } from "react-router";

const BASE_URL = import.meta.env.VITE_API_URL ?? "/api";
const TOKEN_KEY = "jarvis_token";

export type User = { id: number; name: string; email: string };
export type ServiceTool = {
  id: number;
  name: string;
  description: string;
  access: "read" | "write";
  requires_confirmation: boolean;
};
export type Service = {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  tools: ServiceTool[];
};
export type AuthResponse = { token: string; expires_at: string; user: User };

export type Tag = { id: number; name: string };
export type Memo = {
  id: number;
  title: string | null;
  body: string;
  created_at: string;
  tags: Tag[];
};
export type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number };

export const tokenStore = {
  get: () => (typeof window === "undefined" ? null : localStorage.getItem(TOKEN_KEY)),
  set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
};

const CHAT_KEY_PREFIX = "jarvis_chat_";
/** 같은 브라우저를 여러 계정이 써도 대화가 섞이지 않도록 사용자별로 나눈다. */
export const chatStorageKey = (userId: number) => `${CHAT_KEY_PREFIX}${userId}`;

/** 로그아웃 시 저장된 모든 대화를 지운다. 공용 PC에서 대화가 남지 않게 한다. */
export function clearChats() {
  try {
    Object.keys(localStorage)
      .filter((k) => k.startsWith(CHAT_KEY_PREFIX))
      .forEach((k) => localStorage.removeItem(k));
  } catch {
    // 저장소를 쓸 수 없으면 지울 것도 없다.
  }
}

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, string[]> = {},
  ) {
    super(message);
  }
}

export async function api<T>(
  path: string,
  { method = "GET", body }: { method?: string; body?: unknown } = {},
): Promise<T> {
  const token = tokenStore.get();
  let res: Response;
  try {
    res = await fetch(`${BASE_URL}${path}`, {
      method,
      headers: {
        Accept: "application/json",
        ...(body ? { "Content-Type": "application/json" } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError(0, "서버에 연결할 수 없습니다. 잠시 후 다시 시도해 주세요.");
  }

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const message =
      res.status === 429
        ? "요청이 너무 많습니다. 잠시 후 다시 시도해 주세요."
        : (data.message ?? "요청을 처리하지 못했습니다.");
    throw new ApiError(res.status, message, data.errors);
  }
  return data as T;
}

/** 로그인이 필요한 화면의 loader에서 사용. 토큰이 없거나 만료되면 로그인으로 보낸다. */
export async function requireUser(): Promise<User> {
  if (!tokenStore.get()) throw redirect("/login");
  try {
    return await api<User>("/auth/me");
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) {
      tokenStore.clear();
      throw redirect("/login");
    }
    throw e;
  }
}

/** 로그인/회원가입 화면용. 이미 로그인 상태면 대시보드로 보낸다. */
export function redirectIfAuthenticated() {
  if (tokenStore.get()) throw redirect("/");
}
