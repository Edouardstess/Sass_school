namespace STED.RestaurantOS.Application.Abstractions;

/// <summary>A freshly minted QR token: the clear text, and what gets stored.</summary>
public sealed record QrTokenMaterial(string ClearToken, byte[] Hash, string LookupKey);

/// <summary>
/// Mints and verifies table QR tokens.
/// <para>
/// The clear token is returned exactly once, to be printed. Only its hash is
/// persisted, so a database dump yields no working QR codes — the same reasoning
/// as for passwords.
/// </para>
/// </summary>
public interface IQrTokenFactory
{
    QrTokenMaterial Create();

    byte[] Hash(string clearToken);

    string LookupKeyOf(string clearToken);
}
