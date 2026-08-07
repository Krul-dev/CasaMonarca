const CURP_PATTERN = /^[A-Z][AEIOUX][A-Z]{2}\d{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01])[HM](?:AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-J0-9]\d$/
const CURP_DICTIONARY = '0123456789ABCDEFGHIJKLMN\u00d1OPQRSTUVWXYZ'

export const normalizeCurp = (value: string) => value.trim().toUpperCase()

export const isValidCurp = (value: string) => {
  const curp = normalizeCurp(value)

  if (!CURP_PATTERN.test(curp)) {
    return false
  }

  const century = /\d/.test(curp[16]) ? 1900 : 2000
  const year = century + Number(curp.slice(4, 6))
  const month = Number(curp.slice(6, 8))
  const day = Number(curp.slice(8, 10))
  const date = new Date(Date.UTC(year, month - 1, day))

  if (
    date.getUTCFullYear() !== year ||
    date.getUTCMonth() !== month - 1 ||
    date.getUTCDate() !== day
  ) {
    return false
  }

  let sum = 0

  for (let index = 0; index < 17; index += 1) {
    const characterValue = CURP_DICTIONARY.indexOf(curp[index])

    if (characterValue < 0) {
      return false
    }

    sum += (characterValue % 10) * (18 - index)
  }

  return Number(curp[17]) === (10 - (sum % 10)) % 10
}
