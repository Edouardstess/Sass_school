using System.Security.Cryptography;
using System.Text;
using STED.RestaurantOS.Application.Abstractions;

namespace STED.RestaurantOS.Infrastructure.Identity;

/// <summary>
/// Mints table QR tokens.
/// <para>
/// 32 random bytes, base64url. Not a sequential id, not a hash of the table
/// number, nothing guessable: <c>/order/t/12</c> would let anyone order for any
/// table in the venue from the car park.
/// </para>
/// <para>
/// Only the hash is stored. The lookup key is a short, non-secret prefix that
/// turns resolution into an index seek instead of a scan of every code in the
/// database — knowing it reveals nothing, because the full hash is still checked
/// in constant time.
/// </para>
/// </summary>
public sealed class QrTokenFactory : IQrTokenFactory
{
    private const int TokenBytes = 32;
    private const int LookupKeyLength = 12;

    public QrTokenMaterial Create()
    {
        var clear = Convert.ToBase64String(RandomNumberGenerator.GetBytes(TokenBytes))
            .Replace('+', '-')
            .Replace('/', '_')
            .TrimEnd('=');

        return new QrTokenMaterial(clear, Hash(clear), LookupKeyOf(clear));
    }

    public byte[] Hash(string clearToken) => SHA256.HashData(Encoding.UTF8.GetBytes(clearToken));

    public string LookupKeyOf(string clearToken)
        => clearToken.Length <= LookupKeyLength ? clearToken : clearToken[..LookupKeyLength];

    /// <summary>
    /// Constant-time comparison. Comparing hashes with <c>==</c> leaks how many
    /// leading bytes matched, which is enough to reconstruct a token given
    /// patience.
    /// </summary>
    public static bool Matches(byte[] storedHash, byte[] candidateHash)
        => CryptographicOperations.FixedTimeEquals(storedHash, candidateHash);
}
