import axios from "axios";
import { api } from "./api";
import { contextGate } from "./context";

export async function unlockCashier(pin: string): Promise<void> {
  const context = contextGate.capture();
  // Authentication attempts must never be cached/replayed as idempotent commands.
  await api.post(
    "/api/v1/clinic/cashier/unlock",
    { cashier_pin: pin },
    { signal: context.signal },
  );
  if (!context.current()) throw new axios.CanceledError("Context changed");
}
