import { modules, type ModuleCommand } from '@/app/modules'

export type CommandAction = ModuleCommand

/** Komut paleti hızlı işlemleri: tüm modüllerden toplanır. */
export const commandActions: CommandAction[] = modules.flatMap((m) => m.commands ?? [])
