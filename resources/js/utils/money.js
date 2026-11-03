/** A figure for reading: thousands separated, no more than two decimals. */
export const money = (value) => Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 })
