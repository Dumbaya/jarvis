import { redirect } from "react-router";

const BASE_URL = import.meta.env.VITE_API_URL ?? "/api";
const TOKEN_KEY = "jarvis_token";

export type User = { id: number; name: string; email: string; is_admin: boolean };

export type LimitWindow = { limit: number; remaining: number; reset_in: number };
/** ai는 우리 앱의 채팅 횟수와 별개인, AI 제공자 쪽(무료 한도) 상태다. */
export type AiStatus = { mode: "llm" | "rule"; available: boolean; retry_after: number | null };
export type ChatLimits = { minute: LimitWindow; day: LimitWindow; ai?: AiStatus };

/** 초를 "N초", "N분", "N시간"으로 읽기 좋게 바꾼다. */
export function humanizeSeconds(seconds: number): string {
  if (seconds < 120) return `${Math.max(1, seconds)}초`;
  if (seconds < 7200) return `${Math.ceil(seconds / 60)}분`;
  return `${Math.ceil(seconds / 3600)}시간`;
}

/** 서비스가 꺼져 있으면 null, 조회에 실패하면 { error }, 성공하면 데이터. */
export type Section<T> = T | { error: string } | null;
export const sectionError = <T>(s: Section<T>): s is { error: string } => s !== null && typeof s === "object" && "error" in s;

export type BriefingTodo = { id: number; title: string; due_date: string | null };
export type Briefing = {
  period: "today" | "week";
  date: string;
  todos: Section<{
    overdue_count: number;
    overdue: BriefingTodo[];
    due_count: number;
    due: BriefingTodo[];
    completed_this_week: number | null;
  }>;
  habits: Section<{
    total: number;
    checked_today: number;
    unchecked: { id: number; name: string; streak: number }[];
    best_streak: { name: string; streak: number } | null;
    week_checks: number;
    week_possible: number;
  }>;
  reservations: Section<{
    count: number;
    items: { id: number; room: string; title: string | null; starts_at: string; ends_at: string }[];
  }>;
  ledger: Section<{
    month: string;
    income: number;
    expense: number;
    balance: number;
    top_expense: { category: string; total: number; percent: number } | null;
  }>;
};

export type ModelUsage = {
  model: string;
  requests: number;
  input_tokens: number;
  output_tokens: number;
  total_tokens: number;
  estimated_cost_usd: number | null;
};
export type Usage = {
  me: { tokens_today: number; requests_month: number };
  /** 관리자에게만 내려온다. 일반 사용자는 항상 null. */
  admin: {
    tokens_today: number;
    requests_month: number;
    estimated_cost_usd: number | null;
    unpriced_models: string[];
    by_model: ModelUsage[];
    budget: { monthly_usd: number | null; used_percent: number | null; enforced: boolean };
    /** AI 연결 체인의 모델별 상태. 한도가 소진된 모델은 blocked_for(초) 동안 건너뛴다. */
    llm: { available: boolean; retry_after: number | null; models: { id: string; blocked_for: number }[] } | null;
  } | null;
};
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
  // 검색 결과에만 있다. body는 앞부분만 오므로(body_truncated) 검색 결과에서는 수정하지 않는다.
  match?: "keyword" | "semantic" | "both";
  similarity?: number | null;
  excerpt?: string | null;
  body_truncated?: boolean;
};
/** 의미 검색을 썼는지, 못 썼다면 이유. */
export type SemanticInfo = { used: boolean; reason: "disabled" | "indexing" | "unavailable" | "quota" | null };
export type MemoAiStatus = {
  enabled: boolean;
  model: string;
  total: number;
  indexed: number;
  available: boolean;
  retry_after: number | null;
};
export type Todo = {
  id: number;
  title: string;
  due_date: string | null;
  completed: boolean;
  completed_at: string | null;
  overdue: boolean;
};
export type Habit = {
  id: number;
  name: string;
  checked_today: boolean;
  streak: number;
  week_checks: number;
  week_days: number;
};

export type LedgerEntry = {
  id: number;
  type: "income" | "expense";
  amount: number;
  category: string;
  memo: string | null;
  occurred_on: string;
  /** 고정 거래 규칙이 만든 거래인지 */
  recurring: boolean;
};
export type LedgerRule = {
  id: number;
  type: "income" | "expense";
  amount: number;
  category: string;
  memo: string | null;
  frequency: "monthly" | "weekly" | "yearly";
  day_of_month: number | null;
  weekday: number | null;
  month_of_year: number | null;
  start_on: string;
  end_on: string | null;
  active: boolean;
  next_on: string | null;
};
export type CategoryTotal = { category: string; total: number; count: number; percent: number };
export type LedgerSummary = {
  month: string;
  income: number;
  expense: number;
  balance: number;
  entry_count: number;
  expense_by_category: CategoryTotal[];
  income_by_category: CategoryTotal[];
  /** 고정 거래: 이미 반영된 합계와 이 달에 아직 도래하지 않은 예정 합계 */
  fixed: { expense: number; income: number; upcoming_expense: number; upcoming_income: number };
};

export type Room = { id: number; name: string; description: string | null; capacity: number | null };
export type Reservation = {
  id: number;
  room: { id: number; name: string };
  title: string | null;
  starts_at: string;
  ends_at: string;
  status: "confirmed" | "cancelled";
};
export type Availability = {
  room: { id: number; name: string };
  date: string;
  busy: { starts_at: string; ends_at: string; mine: boolean }[];
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
    /** 서버가 보낸 응답 본문 전체. 429의 남은 횟수(limits)처럼 오류에 딸린 정보를 쓸 때 필요하다. */
    public data: Record<string, any> = {},
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
    // 서버가 기다릴 시간까지 알려 준 429는 그 문구를 그대로 쓰고, 일반 요청 제한은 기본 문구를 쓴다.
    const message =
      res.status === 429 && !data.retry_after
        ? "요청이 너무 많습니다. 잠시 후 다시 시도해 주세요."
        : (data.message ?? "요청을 처리하지 못했습니다.");
    throw new ApiError(res.status, message, data.errors, data);
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
